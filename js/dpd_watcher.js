(function() {
  'use strict';
	/* CODE NON optimiser pour DRUPAL*/
  // Attendre que le DOM soit complètement chargé
  document.addEventListener('DOMContentLoaded', function() {
    console.log('Apres chargement de la page');
    // Fonction pour vérifier la méthode sélectionnée
    function checkShippingMethod() {
		console.log('Run checkShippingMethod');
      // Sélecteur spécifique pour le champ de méthode de livraison
      const selector = 'input[name="shipping_information[shipments][0][shipping_method][0]"]:checked';
      const radio = document.querySelector(selector);
      
      if (radio && radio.value && radio.value.includes('dpd_parcelshop')) {
        // DPD est sélectionné, déclencher le rafraîchissement
        console.log('DPD ParcelShop sélectionné, déclenchement du rafraîchissement...');
        
        // Ici, vous devez appeler la fonction qui déclenche attachAjaxRefresh
        // Cette fonction dépend de comment votre système AJAX est configuré
        triggerAjaxRefresh();
        return true;
      }
      
      return false;
    }
    
    // Fonction pour déclencher le rafraîchissement AJAX
    function triggerAjaxRefresh() {
      // Dépend de votre implémentation d'AJAX
      // Exemple 1: Si vous avez un bouton avec une classe spécifique
      const refreshButton = document.querySelector('.dpd-ajax-refresh');
      if (refreshButton) {
        refreshButton.click();
        return;
      }
      
      // Exemple 2: Si vous utilisez Drupal.ajax
      if (typeof Drupal !== 'undefined' && Drupal.ajax) {
        // Trouver l'instance AJAX pour votre panneau
        // Vous devrez adapter ce code à votre configuration
      }
      
      // Exemple 3: Rafraîchissement simple de la page
      // window.location.reload();
      
      console.log('Méthode de rafraîchissement AJAX non trouvée');
    }
    
    // Vérifier immédiatement
    checkShippingMethod();
    
    // Écouter les changements sur le champ radio
    const radios = document.querySelectorAll('input[name="shipping_information[shipments][0][shipping_method][0]"]');
    
    radios.forEach(function(radio) {
      radio.addEventListener('change', function() {
        // Vérifier après un petit délai pour laisser le DOM se mettre à jour
        setTimeout(checkShippingMethod, 50);
      });
    });
    
  });

})();