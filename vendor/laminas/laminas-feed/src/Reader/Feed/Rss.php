<?php

declare(strict_types=1);

namespace Laminas\Feed\Reader\Feed;

use DateTime;
use DOMDocument;
use Laminas\Feed\Reader;
use Laminas\Feed\Reader\Collection;
use Laminas\Feed\Reader\Exception;

use function array_key_exists;
use function array_unique;
use function count;
use function is_array;
use function preg_match;
use function strtotime;
use function trim;

class Rss extends AbstractFeed
{
    /**
     * @param null|string $type
     */
    public function __construct(DOMDocument $dom, $type = null)
    {
        parent::__construct($dom, $type);

        $manager = Reader\Reader::getExtensionManager();

        $feed = $manager->get('DublinCore\Feed');
        $feed->setDomDocument($dom);
        $feed->setType($this->data['type']);
        $feed->setXpath($this->xpath);
        $this->extensions['DublinCore\Feed'] = $feed;

        $feed = $manager->get('Atom\Feed');
        $feed->setDomDocument($dom);
        $feed->setType($this->data['type']);
        $feed->setXpath($this->xpath);
        $this->extensions['Atom\Feed'] = $feed;

        if (
            $this->getType() !== Reader\Reader::TYPE_RSS_10
            && $this->getType() !== Reader\Reader::TYPE_RSS_090
        ) {
            $xpathPrefix = '/rss/channel';
        } else {
            $xpathPrefix = '/rdf:RDF/rss:channel';
        }
        foreach ($this->extensions as $extension) {
            $extension->setXpathPrefix($xpathPrefix);
        }
    }

    /**
     * Get a single author
     *
     * @param  int $index
     * @return null|array<string, string>
     */
    public function getAuthor($index = 0)
    {
        $authors = $this->getAuthors();

        return isset($authors[$index]) && is_array($authors[$index])
            ? $authors[$index]
            : null;
    }

    /**
     * Get an array with feed authors
     *
     * @return array
     */
    public function getAuthors()
    {
        if (array_key_exists('authors', $this->data)) {
            return $this->data['authors'];
        }

        $authors   = [];
        $authorsDc = $this->getExtension('DublinCore')->getAuthors();
        if (! empty($authorsDc)) {
            foreach ($authorsDc as $author) {
                $authors[] = [
                    'name' => $author['name'],
                ];
            }
        }

        /**
         * Technically RSS doesn't specific author element use at the feed level
         * but it's supported on a "just in case" basis.
         */
        if (
            $this->getType() !== Reader\Reader::TYPE_RSS_10
            && $this->getType() !== Reader\Reader::TYPE_RSS_090
        ) {
            $list = $this->xpath->query('//author');
        } else {
            $list = $this->xpath->query('//rss:author');
        }
        if ($list->length) {
            foreach ($list as $author) {
                $string = trim($author->nodeValue);
                $data   = [];
                // Pretty rough parsing - but it's a catchall
                if (preg_match('/^.*@[^ ]*/', $string, $matches)) {
                    $data['email'] = trim($matches[0]);
                    if (preg_match('/\((.*)\)$/', $string, $matches)) {
                        $data['name'] = $matches[1];
                    }
                    $authors[] = $data;
                }
            }
        }

        if (count($authors) === 0) {
            $authors = $this->getExtension('Atom')->getAuthors();
        } else {
            $authors = new Reader\Collection\Author(
                Reader\Reader::arrayUnique($authors)
            );
        }

        if (count($authors) === 0) {
            $authors = null;
        }

        $this->data['authors'] = $authors;

        return $this->data['authors'];
    }

    /**
     * Get the copyright entry
     *
     * @return null|string
     */
    public function getCopyright()
    {
        if (array_key_exists('copyright', $this->data)) {
            return $this->data['copyright'];
        }

        $copyright = null;

        if (
            $this->getType() !== Reader\Reader::TYPE_RSS_10
            && $this->getType() !== Reader\Reader::TYPE_RSS_090
        ) {
            $copyright = $this->xpath->evaluate('string(/rss/channel/copyright)');
        }

        if (! $copyright && $this->getExtension('DublinCore') !== null) {
            $copyright = $this->getExtension('DublinCore')->getCopyright();
        }

        if (empty($copyright)) {
            $copyright = $this->getExtension('Atom')->getCopyright();
        }

        if (! $copyright) {
            $copyright = null;
        }

        $this->data['copyright'] = $copyright;

        return $this->data['copyright'];
    }

    /**
     * Get the feed creation date
     *
     * @return null|DateTime
     */
    public function getDateCreated()
    {
        return $this->getDateModified();
    }

    /**
     * Get the feed modification date
     *
     * @return DateTime
     * @throws Exception\RuntimeException
     */
    public function getDateModified()
    {
        if (array_key_exists('datemodified', $this->data)) {
            return $this->data['datemodified'];
        }

        $date = null;

        if (
            $this->getType() !== Reader\Reader::TYPE_RSS_10
            && $this->getType() !== Reader\Reader::TYPE_RSS_090
        ) {
            $dateModified = $this->xpath->evaluate('string(/rss/channel/pubDate)');
            if (! $dateModified) {
                $dateModified = $this->xpath->evaluate('string(/rss/channel/lastBuildDate)');
            }
            if ($dateModified) {
                $dateModifiedParsed = strtotime($dateModified);
                if ($dateModifiedParsed) {
                    $date = new DateTime('@' . $dateModifiedParsed);
                } else {
                    $dateStandards = [
                        DateTime::RSS,
                        DateTime::RFC822,
                        DateTime::RFC2822,
                    ];
                    foreach ($dateStandards as $standard) {
                        $date = DateTime::createFromFormat(
                            $standard,
                            $dateModified
                        );
                        if ($date instanceof DateTime) {
                            break;
                        }
                    }
                    if (! $date) {
                        throw new Exception\RuntimeException(
                            'Could not load date due to unrecognised'
                            . ' format (should follow RFC 822 or 2822).'
                        );
                    }
                }
            }
        }

        if (! $date) {
            $date = $this->getExtension('DublinCore')->getDate();
        }

        if (! $date) {
            $date = $this->getExtension('Atom')->getDateModified();
        }

        if (! $date) {
            $date = null;
        }

        $this->data['datemodified'] = $date;

        return $this->data['datemodified'];
    }

    /**
     * Get the feed lastBuild date
     *
     * @return DateTime
     * @throws Exception\RuntimeException
     */
    public function getLastBuildDate()
    {
        if (array_key_exists('lastBuildDate', $this->data)) {
            return $this->data['lastBuildDate'];
        }

        $date = null;

        if (
            $this->getType() !== Reader\Reader::TYPE_RSS_10
            && $this->getType() !== Reader\Reader::TYPE_RSS_090
        ) {
            $lastBuildDate = $this->xpath->evaluate('string(/rss/channel/lastBuildDate)');
            if ($lastBuildDate) {
                $lastBuildDateParsed = strtotime($lastBuildDate);
                if ($lastBuildDateParsed) {
                    $date = new DateTime('@' . $lastBuildDateParsed);
                } else {
                    $dateStandards = [
                        DateTime::RSS,
                        DateTime::RFC822,
                        DateTime::RFC2822,
                        null,
                    ];
                    foreach ($dateStandards as $standard) {
                        try {
                            $date = DateTime::createFromFormat($standard, $lastBuildDateParsed);
                            break;
                        } catch (\Exception $e) {
                            if ($standard === null) {
                                throw new Exception\RuntimeException(
                                    'Could not load date due to unrecognised format'
                                    . ' (should follow RFC 822 or 2822): ' . $e->getMessage(),
                                    0,
                                    $e
                                );
                            }
                        }
                    }
                }
            }
        }

        if (! $date) {
            $date = null;
        }

        $this->data['lastBuildDate'] = $date;

        return $this->data['lastBuildDate'];
    }

    /**
     * Get the feed description
     *
     * @return null|string
     */
    public function getDescription()
    {
        if (array_key_exists('description', $this->data)) {
            return $this->data['description'];
        }

        if (
            $this->getType() !== Reader\Reader::TYPE_RSS_10
            && $this->getType() !== Reader\Reader::TYPE_RSS_090
        ) {
            $description = $this->xpath->evaluate('string(/rss/channel/description)');
        } else {
            $description = $this->xpath->evaluate('string(/rdf:RDF/rss:channel/rss:description)');
        }

        if (! $description && $this->getExtension('DublinCore') !== null) {
            $description = $this->getExtension('DublinCore')->getDescription();
        }

        if (empty($description)) {
            $description = $this->getExtension('Atom')->getDescription();
        }

        if (! $description) {
            $description = null;
        }

        $this->data['description'] = $description;

        return $this->data['description'];
    }

    /**
     * Get the feed ID
     *
     * @return null|string
     */
    public function getId()
    {
        if (array_key_exists('id', $this->data)) {
            return $this->data['id'];
        }

        $id = null;

        if (
            $this->getType() !== Reader\Reader::TYPE_RSS_10
            && $this->getType() !== Reader\Reader::TYPE_RSS_090
        ) {
            $id = $this->xpath->evaluate('string(/rss/channel/guid)');
        }

        if (! $id && $this->getExtension('DublinCore') !== null) {
            $id = $this->getExtension('DublinCore')->getId();
        }

        if (empty($id)) {
            $id = $this->getExtension('Atom')->getId();
        }

        if (! $id) {
            if ($this->getLink()) {
                $id = $this->getLink();
            } elseif ($this->getTitle()) {
                $id = $this->getTitle();
            } else {
                $id = null;
            }
        }

        $this->data['id'] = $id;

        return $this->data['id'];
    }

    /**
     * Get the feed image data
     *
     * @return null|array
     */
    public function getImage()
    {
        if (array_key_exists('image', $this->data)) {
            return $this->data['image'];
        }

        if (
            $this->getType() !== Reader\Reader::TYPE_RSS_10
            && $this->getType() !== Reader\Reader::TYPE_RSS_090
        ) {
            $list   = $this->xpath->query('/rss/channel/image');
            $prefix = '/rss/channel/image[1]';
        } else {
            $list   = $this->xpath->query('/rdf:RDF/rss:channel/rss:image');
            $prefix = '/rdf:RDF/rss:channel/rss:image[1]';
        }
        if ($list->length > 0) {
            $image = [];
            $value = $this->xpath->evaluate('string(' . $prefix . '/url)');
            if ($value) {
                $image['uri'] = $value;
            }
            $value = $this->xpath->evaluate('string(' . $prefix . '/link)');
            if ($value) {
                $image['link'] = $value;
            }
            $value = $this->xpath->evaluate('string(' . $prefix . '/title)');
            if ($value) {
                $image['title'] = $value;
            }
            $value = $this->xpath->evaluate('string(' . $prefix . '/height)');
            if ($value) {
                $image['height'] = $value;
            }
            $value = $this->xpath->evaluate('string(' . $prefix . '/width)');
            if ($value) {
                $image['width'] = $value;
            }
            $value = $this->xpath->evaluate('string(' . $prefix . '/description)');
            if ($value) {
                $image['description'] = $value;
            }
        } else {
            $image = null;
        }

        $this->data['image'] = $image;

        return $this->data['image'];
    }

    /**
     * Get the feed language
     *
     * @return null|string
     */
    public function getLanguage()
    {
        if (array_key_exists('language', $this->data)) {
            return $this->data['language'];
        }

        $language = null;

        if (
            $this->getType() !== Reader\Reader::TYPE_RSS_10
            && $this->getType() !== Reader\Reader::TYPE_RSS_090
        ) {
            $language = $this->xpath->evaluate('string(/rss/channel/language)');
        }

        if (! $language && $this->getExtension('DublinCore') !== null) {
            $language = $this->getExtension('DublinCore')->getLanguage();
        }

        if (empty($language)) {
            $language = $this->getExtension('Atom')->getLanguage();
        }

        if (! $language) {
            $language = $this->xpath->evaluate('string(//@xml:lang[1])');
        }

        if (! $language) {
            $language = null;
        }

        $this->data['language'] = $language;

        return $this->data['language'];
    }

    /**
     * Get a link to the feed
     *
     * @return null|string
     */
    public function getLink()
    {
        if (array_key_exists('link', $this->data)) {
            return $this->data['link'];
        }

        if (
            $this->getType() !== Reader\Reader::TYPE_RSS_10
            && $this->getType() !== Reader\Reader::TYPE_RSS_090
        ) {
            $link = $this->xpath->evaluate('string(/rss/channel/link)');
        } else {
            $link = $this->xpath->evaluate('string(/rdf:RDF/rss:channel/rss:link)');
        }

        if (empty($link)) {
            $link = $this->getExtension('Atom')->getLink();
        }

        if (! $link) {
            $link = null;
        }

        $this->data['link'] = $link;

        return $this->data['link'];
    }

    /**
     * Get a link to the feed XML
     *
     * @return null|string
     */
    public function getFeedLink()
    {
        if (array_key_exists('feedlink', $this->data)) {
            return $this->data['feedlink'];
        }

        $link = $this->getExtension('Atom')->getFeedLink();

        if ($link === null || empty($link)) {
            $link = $this->getOriginalSourceUri();
        }

        $this->data['feedlink'] = $link;

        return $this->data['feedlink'];
    }

    /**
     * Get the feed generator entry
     *
     * @return null|string
     */
    public function getGenerator()
    {
        if (array_key_exists('generator', $this->data)) {
            return $this->data['generator'];
        }

        $generator = null;

        if (
            $this->getType() !== Reader\Reader::TYPE_RSS_10
            && $this->getType() !== Reader\Reader::TYPE_RSS_090
        ) {
            $generator = $this->xpath->evaluate('string(/rss/channel/generator)');
        }

        if (! $generator) {
            if (
                $this->getType() !== Reader\Reader::TYPE_RSS_10
                && $this->getType() !== Reader\Reader::TYPE_RSS_090
            ) {
                $generator = $this->xpath->evaluate('string(/rss/channel/atom:generator)');
            } else {
                $generator = $this->xpath->evaluate('string(/rdf:RDF/rss:channel/atom:generator)');
            }
        }

        if (empty($generator)) {
            $generator = $this->getExtension('Atom')->getGenerator();
        }

        if (! $generator) {
            $generator = null;
        }

        $this->data['generator'] = $generator;

        return $this->data['generator'];
    }

    /**
     * Get the feed title
     *
     * @return null|string
     */
    public function getTitle()
    {
        if (array_key_exists('title', $this->data)) {
            return $this->data['title'];
        }

        if (
            $this->getType() !== Reader\Reader::TYPE_RSS_10
            && $this->getType() !== Reader\Reader::TYPE_RSS_090
        ) {
            $title = $this->xpath->evaluate('string(/rss/channel/title)');
        } else {
            $title = $this->xpath->evaluate('string(/rdf:RDF/rss:channel/rss:title)');
        }

        if (! $title && $this->getExtension('DublinCore') !== null) {
            $title = $this->getExtension('DublinCore')->getTitle();
        }

        if (! $title) {
            $title = $this->getExtension('Atom')->getTitle();
        }

        if (! $title) {
            $title = null;
        }

        $this->data['title'] = $title;

        return $this->data['title'];
    }

    /**
     * Get an array of any supported Pusubhubbub endpoints
     *
     * @return null|array
     */
    public function getHubs()
    {
        if (array_key_exists('hubs', $this->data)) {
            return $this->data['hubs'];
        }

        $hubs = $this->getExtension('Atom')->getHubs();

        if (empty($hubs)) {
            $hubs = null;
        } else {
            $hubs = array_unique($hubs);
        }

        $this->data['hubs'] = $hubs;

        return $this->data['hubs'];
    }

    /**
     * Get all categories
     *
     * @return Reader\Collection\Category
     */
    public function getCategories()
    {
        if (array_key_exists('categories', $this->data)) {
            return $this->data['categories'];
        }

        if (
            $this->getType() !== Reader\Reader::TYPE_RSS_10
            && $this->getType() !== Reader\Reader::TYPE_RSS_090
        ) {
            $list = $this->xpath->query('/rss/channel//category');
        } else {
            $list = $this->xpath->query('/rdf:RDF/rss:channel//rss:category');
        }

        if ($list->length) {
            $categoryCollection = new Collection\Category();
            foreach ($list as $category) {
                $categoryCollection[] = [
                    'term'   => $category->nodeValue,
                    'scheme' => $category->getAttribute('domain'),
                    'label'  => $category->nodeValue,
                ];
            }
        } else {
            $categoryCollection = $this->getExtension('DublinCore')->getCategories();
        }

        if (count($categoryCollection) === 0) {
            $categoryCollection = $this->getExtension('Atom')->getCategories();
        }

        $this->data['categories'] = $categoryCollection;

        return $this->data['categories'];
    }

    /**
     * Read all entries to the internal entries array
     *
     * @return void
     */
    protected function indexEntries()
    {
        if ($this->getType() !== Reader\Reader::TYPE_RSS_10 && $this->getType() !== Reader\Reader::TYPE_RSS_090) {
            $entries = $this->xpath->evaluate('//item');
        } else {
            $entries = $this->xpath->evaluate('//rss:item');
        }

        foreach ($entries as $index => $entry) {
            $this->entries[$index] = $entry;
        }
    }

    /**
     * Register the default namespaces for the current feed format
     *
     * @return void
     */
    protected function registerNamespaces()
    {
        switch ($this->data['type']) {
            case Reader\Reader::TYPE_RSS_10:
                $this->xpath->registerNamespace('rdf', Reader\Reader::NAMESPACE_RDF);
                $this->xpath->registerNamespace('rss', Reader\Reader::NAMESPACE_RSS_10);
                break;

            case Reader\Reader::TYPE_RSS_090:
                $this->xpath->registerNamespace('rdf', Reader\Reader::NAMESPACE_RDF);
                $this->xpath->registerNamespace('rss', Reader\Reader::NAMESPACE_RSS_090);
                break;
        }
    }
}
