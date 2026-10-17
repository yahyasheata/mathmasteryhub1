(function () {
  'use strict';

  document.querySelectorAll('[data-course-carousel]').forEach(function (carousel) {
    var track = carousel.querySelector('[data-carousel-track]');
    var slides = Array.prototype.slice.call(carousel.querySelectorAll('[data-carousel-slide]'));
    var previous = carousel.querySelector('[data-carousel-previous]');
    var next = carousel.querySelector('[data-carousel-next]');
    var status = carousel.querySelector('[data-carousel-status]');
    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    var activeIndex = 0;
    var hovering = false;
    var focused = false;
    var timer = null;
    var interval = 6000;

    if (!track || slides.length < 2 || !previous || !next) {
      carousel.querySelector('.landing-carousel-controls')?.setAttribute('hidden', '');
      return;
    }

    function stopTimer() {
      if (timer !== null) window.clearInterval(timer);
      timer = null;
    }

    function isPaused() {
      return hovering || focused || document.hidden || reducedMotion.matches;
    }

    function currentIndex() {
      var scrollPosition = track.scrollLeft;
      var nearest = 0;
      var distance = Infinity;
      slides.forEach(function (slide, index) {
        var offset = slide.offsetLeft - track.offsetLeft;
        var nextDistance = Math.abs(offset - scrollPosition);
        if (nextDistance < distance) { nearest = index; distance = nextDistance; }
      });
      return nearest;
    }

    function goTo(index, announce) {
      activeIndex = (index + slides.length) % slides.length;
      var target = slides[activeIndex];
      track.scrollTo({
        left: target.offsetLeft - track.offsetLeft,
        behavior: reducedMotion.matches ? 'auto' : 'smooth'
      });
      if (announce && status) status.textContent = 'Course ' + (activeIndex + 1) + ' of ' + slides.length;
    }

    function schedule() {
      stopTimer();
      if (!isPaused()) timer = window.setInterval(function () { goTo(activeIndex + 1, false); }, interval);
    }

    previous.addEventListener('click', function () { goTo(currentIndex() - 1, true); });
    next.addEventListener('click', function () { goTo(currentIndex() + 1, true); });
    track.addEventListener('scroll', function () {
      window.requestAnimationFrame(function () { activeIndex = currentIndex(); });
    }, { passive: true });
    track.addEventListener('keydown', function (event) {
      if (event.target !== track) return;
      if (event.key === 'ArrowRight') { event.preventDefault(); goTo(currentIndex() + 1, true); }
      if (event.key === 'ArrowLeft') { event.preventDefault(); goTo(currentIndex() - 1, true); }
      if (event.key === 'Home') { event.preventDefault(); goTo(0, true); }
      if (event.key === 'End') { event.preventDefault(); goTo(slides.length - 1, true); }
    });
    carousel.addEventListener('mouseenter', function () { hovering = true; schedule(); });
    carousel.addEventListener('mouseleave', function () { hovering = false; schedule(); });
    carousel.addEventListener('focusin', function () { focused = true; schedule(); });
    carousel.addEventListener('focusout', function () {
      window.setTimeout(function () {
        focused = carousel.contains(document.activeElement);
        schedule();
      }, 0);
    });
    document.addEventListener('visibilitychange', schedule);
    reducedMotion.addEventListener('change', schedule);

    schedule();
  });
}());
