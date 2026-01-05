(function (Drupal, drupalSettings, once, L) {
  "use strict";

  /**
   * DPD Map behavior.
   */
  Drupal.behaviors.commerce_dpd = {
    attach: function (context, settings) {
      const mapContainers = once("dpd-map", "#dpd-map-container", context);
      if (mapContainers.length > 0) {
        console.log("DPD Map behavior attached.", settings, mapContainers, drupalSettings);
        mapContainers.forEach((container) => {
          this.initMap(container, settings);
        });
      }
    },

    /**
     * Initialize the OpenStreetMap.
     */
    initMap: function (container, settings) {
      const mapPoints = settings.commerce_dpd?.map_points || [];
      const selectedId = settings.commerce_dpd?.selected_id;
      const texts = settings.commerce_dpd?.text || {};

      if (!mapPoints.length) {
        container.innerHTML = `<p style="padding: 20px; text-align: center;">${texts.no_points || "No pickup points found for this address."}</p>`;
        return;
      }

      // Initialize map
      const firstPoint = mapPoints[0];
      const map = L.map(container).setView([firstPoint.lat, firstPoint.lon], 12);

      // Add OpenStreetMap tiles
      L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        attribution: "© OpenStreetMap contributors",
        maxZoom: 19,
      }).addTo(map);

      // Create markers layer
      const markers = L.layerGroup().addTo(map);
      const markerInstances = new Map();

      // Create markers for each point
      mapPoints.forEach((point) => {
        const marker = L.marker([point.lat, point.lon], {
          title: point.name,
        });

        // Create popup content
        const popupContent = document.createElement("div");
        popupContent.className = "dpd-map-popup";
        popupContent.innerHTML = `
          <h5>${this.escapeHtml(point.name)}</h5>
          <div>${this.escapeHtml(point.address)}</div>
          <div><strong>${texts.distance || "Distance:"}</strong> ${point.distance} km</div>
          <button class="dpd-select-from-map btn btn-primary btn-sm" data-id="${point.id}">
            ${texts.select_point || "Select this point"}
          </button>
        `;

        marker.bindPopup(popupContent);
        marker.addTo(markers);

        // Store marker reference
        markerInstances.set(point.id, marker);

        // Handle marker click
        marker.on("click", () => {
          this.selectParcelShop(point.id);
        });

        // Handle popup button click
        popupContent.querySelector(".dpd-select-from-map").addEventListener("click", (e) => {
          e.preventDefault();
          this.selectParcelShop(point.id);
          map.closePopup();
        });

        // Open popup if this is the selected point
        if (point.id == selectedId) {
          marker.openPopup();
        }
      });

      // Handle radio button changes
      const radios = document.querySelectorAll('input[name="selected_parcelshop"]');
      radios.forEach((radio) => {
        radio.addEventListener("change", (e) => {
          if (e.target.checked) {
            const pointId = e.target.value;
            const marker = markerInstances.get(parseInt(pointId));

            if (marker) {
              marker.openPopup();
              map.setView(marker.getLatLng(), 14);
            }
          }
        });
      });

      // Handle clicks on parcel shop options (the div wrapper)
      const parcelOptions = document.querySelectorAll(".parcelshop-option");
      parcelOptions.forEach((option) => {
        option.addEventListener("click", (e) => {
          const pointId = option.getAttribute("data-id");
          const radio = document.querySelector(`input[name="selected_parcelshop"][value="${pointId}"]`);

          if (radio) {
            radio.checked = true;
            radio.dispatchEvent(new Event("change", { bubbles: true }));
          }
        });
      });

      // Fit map bounds to show all markers
      if (mapPoints.length > 1) {
        const bounds = L.latLngBounds(mapPoints.map((p) => [p.lat, p.lon]));
        map.fitBounds(bounds, { padding: [50, 50] });
      }

      // Store map instance for potential external use
      container.mapInstance = map;
    },

    /**
     * Select a parcel shop by ID.
     */
    selectParcelShop: function (pointId) {
      const radio = document.querySelector(`input[name="selected_parcelshop"][value="${pointId}"]`);

      if (radio) {
        radio.checked = true;
        radio.dispatchEvent(new Event("change", { bubbles: true }));

        // Add visual feedback to the radio option
        this.highlightSelectedOption(pointId);
      }
    },

    /**
     * Highlight the selected option in the list.
     */
    highlightSelectedOption: function (pointId) {
      // Remove previous highlights
      document.querySelectorAll(".parcelshop-option").forEach((option) => {
        option.classList.remove("selected");
      });

      // Add highlight to current selection
      const selectedOption = document.querySelector(`.parcelshop-option[data-id="${pointId}"]`);
      if (selectedOption) {
        selectedOption.classList.add("selected");
      }
    },

    /**
     * Escape HTML to prevent XSS.
     */
    escapeHtml: function (text) {
      const div = document.createElement("div");
      div.textContent = text;
      return div.innerHTML;
    },
  };
})(window.Drupal, window.drupalSettings, window.once, window.L);
