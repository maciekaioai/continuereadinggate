(function () {
  if (typeof tlwReadGateSettings === "undefined") {
    return;
  }

  const settings = tlwReadGateSettings;
  const overlay = document.getElementById("tlw-read-gate-overlay");
  if (!overlay) {
    return;
  }

  const form = document.getElementById("tlw-read-gate-form");
  const emailInput = document.getElementById("tlw-read-gate-email");
  const errorEl = overlay.querySelector(".tlw-read-gate-error");
  let modalShown = false;
  let modalShownAt = 0;
  let interactionHappened = false;
  let meaningfulScrollCount = 0;
  let lastMeaningfulTime = 0;
  let lastScrollY = window.scrollY;

  const focusableSelectors =
    'input, button, select, textarea, a[href], [tabindex]:not([tabindex="-1"])';

  function lockBody() {
    document.body.classList.add("tlw-read-gate-lock");
  }

  function unlockBody() {
    document.body.classList.remove("tlw-read-gate-lock");
  }

  function setError(message) {
    errorEl.textContent = message || "";
  }

  function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  function trapFocus(event) {
    if (event.key !== "Tab") {
      return;
    }
    const focusables = overlay.querySelectorAll(focusableSelectors);
    if (!focusables.length) {
      return;
    }
    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function showModal() {
    if (modalShown) {
      return;
    }
    modalShown = true;
    fetch(settings.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        action: "tlw_gate_token",
        nonce: settings.tokenNonce,
      }),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data && data.success && data.data && data.data.token) {
          sessionStorage.setItem("tlw_gate_token", data.data.token);
        }
      })
      .finally(() => {
        overlay.classList.add("is-active");
        overlay.setAttribute("aria-hidden", "false");
        lockBody();
        modalShownAt = Date.now();
        emailInput.focus();
      });
  }

  function getContentDepthPercent() {
    const selector = settings.contentSelector;
    if (selector) {
      const container = document.querySelector(selector);
      if (container) {
        const rect = container.getBoundingClientRect();
        const top = rect.top + window.scrollY;
        const height = container.scrollHeight;
        const scrollBottom = window.scrollY + window.innerHeight;
        const depth = ((scrollBottom - top) / height) * 100;
        return Math.max(0, depth);
      }
    }
    const scrollBottom = window.scrollY + window.innerHeight;
    const docHeight = Math.max(
      document.body.scrollHeight,
      document.documentElement.scrollHeight
    );
    return (scrollBottom / docHeight) * 100;
  }

  function onScroll() {
    interactionHappened = true;
    const now = Date.now();
    const delta = Math.abs(window.scrollY - lastScrollY);
    if (delta >= 120 && now - lastMeaningfulTime >= 600) {
      meaningfulScrollCount += 1;
      lastMeaningfulTime = now;
      lastScrollY = window.scrollY;
      const depth = getContentDepthPercent();
      if (
        depth >= settings.scrollDepth &&
        meaningfulScrollCount >= settings.meaningfulScrollCount
      ) {
        showModal();
      }
    }
  }

  function registerInteraction() {
    interactionHappened = true;
  }

  if (settings.previewMode) {
    showModal();
  } else {
    window.addEventListener("scroll", onScroll, { passive: true });
    window.addEventListener("pointerdown", registerInteraction);
    window.addEventListener("keydown", registerInteraction);
    setTimeout(showModal, settings.delayBackstop);
    setTimeout(showModal, settings.delayMax);
  }

  overlay.addEventListener("click", (event) => {
    if (event.target === overlay) {
      event.preventDefault();
    }
  });

  document.addEventListener("keydown", (event) => {
    if (!modalShown) {
      return;
    }
    if (event.key === "Escape") {
      event.preventDefault();
    }
  });

  overlay.addEventListener("keydown", trapFocus);

  form.addEventListener("submit", (event) => {
    event.preventDefault();
    setError("");
    const email = emailInput.value.trim();
    const consent = form.querySelector('input[name="consent"]').checked;
    if (!isValidEmail(email)) {
      setError("Please enter a valid email address.");
      return;
    }
    if (!consent) {
      setError("Please tick the box to continue.");
      return;
    }
    const elapsed = Date.now() - modalShownAt;
    const token = sessionStorage.getItem("tlw_gate_token") || "";
    const payload = new URLSearchParams({
      action: "tlw_gate_submit",
      nonce: settings.nonce,
      email,
      consent: consent ? "1" : "0",
      page_url: window.location.href,
      page_title: document.title,
      company: form.querySelector('input[name="company"]').value,
      token,
      elapsed: elapsed.toString(),
      interaction: interactionHappened ? "1" : "0",
    });

    fetch(settings.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: payload,
    })
      .then((response) => response.json())
      .then((data) => {
        if (data && data.success) {
          setError(data.data.message || "Thanks. You can keep reading.");
          overlay.classList.remove("is-active");
          overlay.setAttribute("aria-hidden", "true");
          unlockBody();
        } else {
          const message =
            (data && data.data && data.data.message) ||
            "Something went wrong. Please try again.";
          setError(message);
        }
      })
      .catch(() => {
        setError("Something went wrong. Please try again.");
      });
  });
})();
