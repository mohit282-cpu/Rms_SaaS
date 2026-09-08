/**
 * RMS SaaS - Universal PWA Engine & Offline/Install Experience Manager
 */

(function () {
  'use strict';

  // -------------------------------------------------------------------
  // 1. Service Worker Registration
  // -------------------------------------------------------------------
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      // Resolve path relative to current page location
      const swPath = getRootRelativePath('service-worker.js');
      navigator.serviceWorker.register(swPath)
        .then(function (registration) {
          console.log('[RMS PWA] Service Worker registered with scope:', registration.scope);
        })
        .catch(function (error) {
          console.warn('[RMS PWA] Service Worker registration failed:', error);
        });
    });
  }

  function getRootRelativePath(file) {
    // If hosted in a subdirectory (e.g. /Rms_SaaS/ or root /), build correct path
    const scriptTag = document.querySelector('script[src*="pwa-app.js"]');
    if (scriptTag) {
      const src = scriptTag.getAttribute('src');
      const parts = src.split('js/pwa-app.js');
      return parts[0] + file;
    }
    return file;
  }

  // -------------------------------------------------------------------
  // 2. Connectivity & Online/Offline UX
  // -------------------------------------------------------------------
  let offlineBanner = null;

  function createOfflineBanner() {
    if (offlineBanner) return offlineBanner;

    offlineBanner = document.createElement('div');
    offlineBanner.id = 'rms-offline-banner';
    offlineBanner.className = 'rms-network-toast rms-toast-offline hidden';
    offlineBanner.setAttribute('role', 'alert');
    offlineBanner.innerHTML = `
      <div class="rms-toast-content">
        <span class="rms-toast-icon">⚡</span>
        <span class="rms-toast-text"><strong>Internet Connection Lost.</strong> RMS SaaS requires an active connection for real-time operations.</span>
      </div>
    `;
    document.body.appendChild(offlineBanner);
    return offlineBanner;
  }

  function updateNetworkStatus() {
    const banner = createOfflineBanner();
    if (!navigator.onLine) {
      banner.classList.remove('hidden');
      banner.classList.add('rms-toast-visible');
    } else {
      if (!banner.classList.contains('hidden')) {
        banner.innerHTML = `
          <div class="rms-toast-content">
            <span class="rms-toast-icon">✅</span>
            <span class="rms-toast-text"><strong>Internet Connection Restored.</strong> System reconnected.</span>
          </div>
        `;
        banner.classList.remove('rms-toast-offline');
        banner.classList.add('rms-toast-online');

        setTimeout(function () {
          banner.classList.add('hidden');
          banner.classList.remove('rms-toast-visible', 'rms-toast-online');
          banner.classList.add('rms-toast-offline');
          banner.innerHTML = `
            <div class="rms-toast-content">
              <span class="rms-toast-icon">⚡</span>
              <span class="rms-toast-text"><strong>Internet Connection Lost.</strong> RMS SaaS requires an active connection for real-time operations.</span>
            </div>
          `;
        }, 3500);
      }
    }
  }

  window.addEventListener('online', updateNetworkStatus);
  window.addEventListener('offline', updateNetworkStatus);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', updateNetworkStatus);
  } else {
    updateNetworkStatus();
  }

  // -------------------------------------------------------------------
  // 3. PWA Installation Experience & Prompt Handling
  // -------------------------------------------------------------------
  let deferredPrompt = null;

  function isStandaloneMode() {
    return (
      window.matchMedia('(display-mode: standalone)').matches ||
      window.navigator.standalone === true ||
      document.referrer.includes('android-app://')
    );
  }

  window.addEventListener('beforeinstallprompt', function (e) {
    // Prevent standard minibar
    e.preventDefault();
    deferredPrompt = e;

    if (isStandaloneMode()) {
      return;
    }

    // Check if user dismissed prompt recently (24h cooldown)
    const lastDismissed = localStorage.getItem('rms_pwa_dismissed_time');
    if (lastDismissed) {
      const hours = (Date.now() - parseInt(lastDismissed, 10)) / (1000 * 60 * 60);
      if (hours < 24) {
        return;
      }
    }

    showInstallBanner();
  });

  function showInstallBanner() {
    if (document.getElementById('rms-install-banner')) return;

    const installBanner = document.createElement('div');
    installBanner.id = 'rms-install-banner';
    installBanner.className = 'rms-install-toast';
    installBanner.innerHTML = `
      <div class="rms-install-card">
        <div class="rms-install-icon">⚡</div>
        <div class="rms-install-info">
          <div class="rms-install-title">Install RMS SaaS App</div>
          <div class="rms-install-desc">Add RMS to your home screen for quick access and an app-like experience.</div>
        </div>
        <div class="rms-install-actions">
          <button type="button" id="rms-install-btn" class="rms-btn-install">Install</button>
          <button type="button" id="rms-dismiss-btn" class="rms-btn-dismiss">Not Now</button>
        </div>
      </div>
    `;

    document.body.appendChild(installBanner);

    document.getElementById('rms-install-btn').addEventListener('click', function () {
      if (deferredPrompt) {
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function (choiceResult) {
          if (choiceResult.outcome === 'accepted') {
            console.log('[RMS PWA] User accepted the install prompt');
          }
          deferredPrompt = null;
          installBanner.remove();
        });
      }
    });

    document.getElementById('rms-dismiss-btn').addEventListener('click', function () {
      localStorage.setItem('rms_pwa_dismissed_time', Date.now().toString());
      installBanner.remove();
    });
  }
})();
