import React, {useEffect, useState} from 'react';
import axios from 'axios';
import {ChevronLeft, ChevronRight, Pause, Play} from 'lucide-react';
import {fallbackSlides, readHeroSlides} from './hero-slide-data.js';

export default function HeroSlider() {
  const [slides,setSlides]=useState(fallbackSlides), [index,setIndex]=useState(0), [paused,setPaused]=useState(false), [hovered,setHovered]=useState(false);
  useEffect(()=>{let active=true;axios.get('/public-api/hero-slides', {headers:{Accept:'application/json'}}).then(({data})=>{const loaded=readHeroSlides(data);if(active){setSlides(loaded);setIndex(0)}}).catch(()=>{if(active)setSlides(fallbackSlides)});return()=>{active=false}},[]);
  useEffect(()=>{if(slides.length<2||paused||hovered||window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;const timer=setInterval(()=>{if(!document.hidden)setIndex(i=>(i+1)%slides.length)},6000);return()=>clearInterval(timer)},[slides.length,paused,hovered,index]);
  if(!slides.length)return null;
  const move=step=>setIndex(i=>(i+step+slides.length)%slides.length);
  return <section className="ex-hero" aria-label="EXOWORK highlights" aria-roledescription="carousel" onMouseEnter={()=>setHovered(true)} onMouseLeave={()=>setHovered(false)} onFocusCapture={()=>setHovered(true)} onBlurCapture={e=>{if(!e.currentTarget.contains(e.relatedTarget))setHovered(false)}}>
    <div className="ex-hero-bg"><i className="b1"/><i className="b2"/><i className="b3"/><b/></div>
    <div className="ex-wrap ex-hero-grid">
      <div className="ex-slider-copy">
        <div className="ex-slide-copy-stack" aria-live={paused?'polite':'off'}>{slides.map((slide,i)=><div className={'ex-slide-copy '+(i===index?'is-active':'')} key={slide.id} aria-hidden={i!==index} role="group" aria-roledescription="slide" aria-label={`${i+1} of ${slides.length}`}><h1>{slide.title}</h1><div className="ex-hero-sub" dangerouslySetInnerHTML={{__html:slide.subtitle}}/></div>)}</div>
        <div className="ex-actions"><a className="ex-btn grad" href="#contact">Join EXOWORK</a><a className="ex-btn ghost" href="#pipeline">See how it works</a></div>
        {slides.length>1&&<div className="ex-slider-controls"><button type="button" onClick={()=>move(-1)} aria-label="Previous slide"><ChevronLeft size={18}/></button>{slides.map((s,i)=><button type="button" className={'ex-slide-dot '+(index===i?'active':'')} aria-label={`Go to slide ${i+1}`} aria-current={index===i?'true':undefined} onClick={()=>setIndex(i)} key={s.id}/>)}<button type="button" onClick={()=>move(1)} aria-label="Next slide"><ChevronRight size={18}/></button><button type="button" onClick={()=>setPaused(p=>!p)} aria-label={paused?'Play slideshow':'Pause slideshow'}>{paused?<Play size={15}/>:<Pause size={15}/>}</button></div>}
      </div>
      <div className="ex-hero-image ex-slide-image-stack">{slides.map((slide,i)=><img key={slide.id} className={i===index?'is-active':''} src={slide.image_url} alt={i===index?slide.title:''} aria-hidden={i!==index}/>)}</div>
    </div>
  </section>;
}
