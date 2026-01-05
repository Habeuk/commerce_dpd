(function(Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Handle parcel shop list interactions.
   */
  Drupal.behaviors.dpdMapEvents = {
    attach: function(context, settings) {
      // Handle enter key on parcel shop options
      const options = once('dpd-options', '.parcelshop-option', context);
      if(options.length > 0)
      options.forEach(option => {
        option.setAttribute('tabindex', '0');
        option.setAttribute('role', 'button');        
        option.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            const pointId = option.getAttribute('data-id');
            const radio = document.querySelector(`input[name="selected_parcelshop"][value="${pointId}"]`);            
            if (radio) {
              radio.checked = true;
              radio.dispatchEvent(new Event('change', { bubbles: true }));
            }
          }
        });
      });
    }
  };

})(Drupal, drupalSettings, once);