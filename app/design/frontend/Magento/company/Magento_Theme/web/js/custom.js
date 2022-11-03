define([
    'jquery',
    // 'mage/smart-keyboard-handler',
    'mage/mage',
    // 'mage/ie-class-fixer',
    // 'domReady!',
    'jquery/ui',
    'slick'
], function ($) {
    'use strict';

    $('.product-info-main, .product-info-left, .product-social-links').mage('sticky', {
        container: '.product.media'
    });


    $('.slick-slider').slick({                    
        dots: true,
        infinite: true,       
        slidesToShow: 3,
        slidesToScroll: 3,
        responsive: [
          {
            breakpoint: 9999,
            settings: 'unslick'
          },
          {
            breakpoint: 1024,
            settings: {
              slidesToShow: 1,
              slidesToScroll: 1,
              arrows: false,
            }
          } 
        ]
    });
    
    $(window).on('resize', function() {
      $('.slick-slider').slick('resize');
    });
        
    
});