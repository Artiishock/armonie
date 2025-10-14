import Swiper from 'swiper';
import 'swiper/css';

document.addEventListener('DOMContentLoaded', () => {
  const swiper = new Swiper('.swiper', {
    slidesPerView: 3,
    spaceBetween: 30,
    pagination: {
      el: '.swiper-pagination',
      clickable: true,
    },
  });

  // Фильтрация
  document.querySelectorAll('.filter-btn').forEach(button => {
    button.addEventListener('click', function() {
      const filter = this.dataset.filter;
      
      // Обновить активную кнопку
      document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.classList.toggle('active', btn === this);
      });

      // Применить фильтр
      document.querySelectorAll('.swiper-slide').forEach(slide => {
        const show = filter === 'all' || slide.dataset.type === filter;
        slide.style.display = show ? 'block' : 'none';
      });

      // Обновить слайдер
      swiper.update();
    });
  });
});