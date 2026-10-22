export const fallbackSlides = [{
  id: 0,
  title: 'From Opportunity to Long-Term Employment.',
  subtitle: 'EXOWORK connects candidates, employers and training partners — helping people move from preparation and opportunity to joining, retention and long-term career growth.<br><br>We don’t just help people to get jobs.<br><strong>We help them build sustainable employment.</strong>',
  image_url: '/hero1.png',
}];

export function readHeroSlides(data) {
  if (!Array.isArray(data?.slides) || !data.slides.every(slide =>
    slide && typeof slide === 'object' && slide.id != null &&
    typeof slide.title === 'string' && typeof slide.subtitle === 'string' &&
    typeof slide.image_url === 'string'
  )) throw new Error('The hero slides response is invalid.');
  return data.slides;
}
