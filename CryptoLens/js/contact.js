/* ============================================================
   CryptoLens — Form Handling (Contact + Booking)
   No server needed — opens pre-filled mailto link
   ============================================================ */

function validateEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function showSuccess(formEl, msgEl) {
  formEl.style.display = 'none';
  if (msgEl) msgEl.style.display = 'block';
}

/* ---- Contact Form ---- */
function initContactForm() {
  const form = document.getElementById('contactForm');
  if (!form) return;

  form.addEventListener('submit', (e) => {
    e.preventDefault();

    const name    = document.getElementById('ctName')?.value.trim()    || '';
    const email   = document.getElementById('ctEmail')?.value.trim()   || '';
    const subject = document.getElementById('ctSubject')?.value.trim() || 'General Question';
    const message = document.getElementById('ctMessage')?.value.trim() || '';

    if (!name || !email || !message) {
      alert('Please fill in all required fields.');
      return;
    }
    if (!validateEmail(email)) {
      alert('Please enter a valid email address.');
      return;
    }

    const body = encodeURIComponent(
      `Name: ${name}\nEmail: ${email}\nSubject: ${subject}\n\nMessage:\n${message}`
    );
    const mailSubject = encodeURIComponent(`[CryptoLens] ${subject}`);
    window.location.href = `mailto:hello@cryptolens.io?subject=${mailSubject}&body=${body}`;

    const successMsg = document.getElementById('contactSuccess');
    if (successMsg) showSuccess(form, successMsg);
  });
}

/* ---- Booking Form ---- */
function initBookingForm() {
  const form = document.getElementById('bookingForm');
  if (!form) return;

  form.addEventListener('submit', (e) => {
    e.preventDefault();

    const name    = document.getElementById('bkName')?.value.trim()    || '';
    const email   = document.getElementById('bkEmail')?.value.trim()   || '';
    const service = document.getElementById('bkService')?.value        || '';
    const date    = document.getElementById('bkDate')?.value.trim()    || 'Flexible';
    const notes   = document.getElementById('bkNotes')?.value.trim()   || 'None provided';

    if (!name || !email || !service) {
      alert('Please fill in your name, email, and select a service.');
      return;
    }
    if (!validateEmail(email)) {
      alert('Please enter a valid email address.');
      return;
    }

    const serviceLabels = {
      'portfolio-review':   'Portfolio Review Session — $49',
      'strategy-workshop':  'Trading Strategy Workshop — $79',
      'alerts':             'Monthly Market Alerts — $19/mo'
    };

    const body = encodeURIComponent(
      `Name: ${name}\nEmail: ${email}\nService: ${serviceLabels[service] || service}\nPreferred Date/Time: ${date}\n\nPortfolio & Goals:\n${notes}`
    );
    const mailSubject = encodeURIComponent(`[CryptoLens Booking] ${serviceLabels[service] || service}`);
    window.location.href = `mailto:hello@cryptolens.io?subject=${mailSubject}&body=${body}`;

    const successMsg = document.getElementById('bookingSuccess');
    if (successMsg) showSuccess(form, successMsg);
  });

  // Scroll-to-form on CTA button clicks
  document.querySelectorAll('[data-scroll-to-form]').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const target = document.getElementById('bookingForm');
      if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        const select = document.getElementById('bkService');
        const val    = btn.dataset.service;
        if (select && val) select.value = val;
      }
    });
  });
}

/* ---- Shared Nav Toggle + Active Link ---- */
function initNav() {
  const toggle = document.getElementById('navToggle');
  const links  = document.getElementById('navLinks');
  if (toggle && links) {
    toggle.addEventListener('click', () => links.classList.toggle('open'));
    // Close when a link is clicked (mobile)
    links.querySelectorAll('a').forEach(a => {
      a.addEventListener('click', () => links.classList.remove('open'));
    });
  }
  // Mark active link
  const currentPath = window.location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.nav-links a').forEach(a => {
    const href = a.getAttribute('href');
    if (href === currentPath || (currentPath === '' && href === 'index.html')) {
      a.classList.add('active');
    }
  });
}

/* ---- FAQ Accordion ---- */
function initFaq() {
  document.querySelectorAll('.faq-question').forEach(btn => {
    btn.addEventListener('click', () => {
      const item   = btn.parentElement;
      const isOpen = item.classList.contains('open');
      document.querySelectorAll('.faq-item').forEach(i => i.classList.remove('open'));
      if (!isOpen) item.classList.add('open');
    });
  });
}

document.addEventListener('DOMContentLoaded', () => {
  initNav();
  initContactForm();
  initBookingForm();
  initFaq();
});
