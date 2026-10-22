import React,{useEffect,useRef,useState} from 'react';
import axios from 'axios';
import {UploadCloud, ImagePlus, CheckCircle2, RotateCcw, Plus, Pencil, Trash2} from 'lucide-react';
import {Modal} from '../../components/UI.jsx';
import './hero-slides.css';
import {readHeroSlides} from '../../components/hero-slide-data.js';

const blank={title:'',subtitle:'',sort_order:0};
export default function HeroSlides(){
  const [slides,setSlides]=useState([]),[form,setForm]=useState(null),[image,setImage]=useState(null),[preview,setPreview]=useState(''),[busy,setBusy]=useState(false),[error,setError]=useState(''),[loading,setLoading]=useState(true),[message,setMessage]=useState('');
  const subtitle=useRef(null),fileInput=useRef(null);
  const [dragging,setDragging]=useState(false),[imageError,setImageError]=useState('');
  const close=()=>{if(busy)return;setForm(null);setImage(null);setImageError('');setError('');setDragging(false)};
  const chooseImage=file=>{
    if(!file)return;
    if(!['image/jpeg','image/png','image/webp'].includes(file.type)){setImageError('Choose a JPG, PNG or WebP image.');return}
    if(file.size>5*1024*1024){setImageError('The image must be 5 MB or smaller.');return}
    setImageError('');setImage(file);
  };
  const load=async()=>{try{const {data}=await axios.get('/public-api/hero-slides', {headers:{Accept:'application/json'}});setSlides(readHeroSlides(data))}catch{setError('Could not load slides. Please retry.')}finally{setLoading(false)}};
  useEffect(()=>{load()},[]);
  useEffect(()=>{if(!image){setPreview('');return}const url=URL.createObjectURL(image);setPreview(url);return()=>URL.revokeObjectURL(url)},[image]);
  const edit=slide=>{setForm(slide?{...slide}:{...blank,sort_order:slides.length?Math.max(...slides.map(s=>s.sort_order))+1:0});setImage(null);setImageError('');setDragging(false);setError('');setMessage('')};
  const format=tag=>{const el=subtitle.current;const start=el.selectionStart,end=el.selectionEnd;const value=form.subtitle;setForm({...form,subtitle:value.slice(0,start)+`<${tag}>`+value.slice(start,end)+`</${tag}>`+value.slice(end)});requestAnimationFrame(()=>{el.focus();el.setSelectionRange(start+tag.length+2,end+tag.length+2)})};
  const headers=()=>({'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content});
  const save=async e=>{e.preventDefault();setBusy(true);setError('');try{const data=new FormData();['title','subtitle','sort_order'].forEach(k=>data.append(k,form[k]));if(image)data.append('image',image);await axios.post('/admin-api/hero-slides'+(form.id?`/${form.id}`:''),data,{headers:headers()});setForm(null);setImage(null);setMessage('Slide saved.');await load()}catch(e){setError(Object.values(e.response?.data?.errors||{}).flat().join(' ')||e.response?.data?.message||'Could not save slide. Try again.')}finally{setBusy(false)}};
  const remove=async slide=>{if(!window.confirm(`Delete “${slide.title}”?`))return;setBusy(true);setError('');try{await axios.delete(`/admin-api/hero-slides/${slide.id}`,{headers:headers()});if(form?.id===slide.id)setForm(null);await load();setMessage('Slide deleted.')}catch(e){setError(e.response?.data?.message||'Could not delete slide.')}finally{setBusy(false)}};
  return <div className="hero-admin"><div className="hero-admin-heading"><div><h1>Homepage slides</h1><p>Manage hero images and text. The homepage buttons stay fixed across slides.</p></div><button className="btn primary" disabled={busy} onClick={()=>edit(null)}><Plus size={16}/> Add slide</button></div>{error&&!form&&<p role="alert" className="hero-admin-error">{error}</p>}{message&&<p role="status">{message}</p>}{loading?<p>Loading slides…</p>:<div className="hero-admin-list">{slides.map((slide,i)=><article key={slide.id}><img src={slide.image_url} alt=""/><div><small>Slide {i+1} · Order {slide.sort_order}</small><h3>{slide.title}</h3><p>{slide.subtitle.replace(/<[^>]*>/g,' ').slice(0,180)}</p></div><div className="hero-admin-buttons"><button className="btn" disabled={busy} onClick={()=>edit(slide)}><Pencil size={15}/> Edit</button><button className="btn" disabled={busy} onClick={()=>remove(slide)}><Trash2 size={15}/> Delete</button></div></article>)}{!slides.length&&<p>No slides yet. Add a slide to display the homepage hero.</p>}</div>}
    <Modal open={!!form} onClose={close} title={form?.id?'Edit homepage slide':'Create homepage slide'} sub="Update your banner and message. Changes appear on the homepage after saving." className="hero-slide-modal" footer={<><button type="button" className="btn" disabled={busy} onClick={close}>Cancel</button><button type="submit" form="hero-slide-form" className="btn primary" disabled={busy}>{busy?'Saving…':'Save slide'}</button></>}>
      {form&&<form id="hero-slide-form" onSubmit={save} className="hero-admin-form">
        {error&&<p role="alert" className="hero-admin-error">{error}</p>}
        <div className="hero-editor-grid">
          <section className="hero-editor-content"><div className="hero-field-heading"><span>01</span><div><h3>Slide content</h3><p>Give visitors a clear, memorable message.</p></div></div>
            <label htmlFor="slide-title">Title <span className="hero-required">*</span></label><input id="slide-title" required maxLength={255} placeholder="Enter your headline" value={form.title} onChange={e=>setForm({...form,title:e.target.value})}/>
            <label htmlFor="slide-subtitle">Subtitle <span className="hero-required">*</span></label>
            <div className="hero-subtitle-editor"><div className="hero-format"><button type="button" title="Make selected text bold" onClick={()=>format('strong')}><b>B</b></button><button type="button" title="Make selected text italic" onClick={()=>format('em')}><i>I</i></button><span>Select text to format</span></div><textarea id="slide-subtitle" ref={subtitle} required maxLength={5000} rows={7} placeholder="Write a short introduction…" value={form.subtitle} onChange={e=>setForm({...form,subtitle:e.target.value})}/></div><small className="hero-field-help">Bold and italic supported. Use &lt;br&gt; for a line break.</small>
            <label htmlFor="slide-order">Display order</label><input id="slide-order" className="hero-order-input" type="number" required min="0" max="65535" value={form.sort_order} onChange={e=>setForm({...form,sort_order:e.target.value})}/><small className="hero-field-help">Lower numbers appear first.</small>
          </section>
          <section className="hero-editor-art"><div className="hero-field-heading"><span>02</span><div><h3>Banner image</h3><p>Choose the artwork for this slide.</p></div></div>
            <input ref={fileInput} className="hero-file-input" type="file" accept="image/png,image/jpeg,image/webp" aria-label="Upload banner image" disabled={busy} onChange={e=>{chooseImage(e.target.files[0]);e.target.value=''}}/>
            <button type="button" disabled={busy} className={'hero-upload-box '+(dragging?'is-dragging':'')} aria-label="Upload banner: drag and drop or click to choose an image" onClick={()=>fileInput.current?.click()} onDragEnter={e=>{e.preventDefault();setDragging(true)}} onDragOver={e=>{e.preventDefault();e.dataTransfer.dropEffect='copy';setDragging(true)}} onDragLeave={e=>{if(!e.currentTarget.contains(e.relatedTarget))setDragging(false)}} onDrop={e=>{e.preventDefault();setDragging(false);if(!busy)chooseImage(e.dataTransfer.files[0])}}><span className="hero-upload-icon"><UploadCloud size={26}/></span><strong>{dragging?'Drop your image here':'Drag & drop your banner'}</strong><span>or <b>click to upload</b></span><small>JPG, PNG or WebP · Maximum 5 MB</small></button>
            {imageError&&<p role="alert" className="hero-admin-error">{imageError}</p>}
            <div className="hero-preview-card"><header><span><ImagePlus size={15}/> Image preview</span>{image&&<em><CheckCircle2 size={14}/> Ready to upload</em>}</header><div className="hero-preview-canvas"><img src={preview||form.image_url||'/hero1.png'} alt="Selected banner preview" onError={()=>{if(image){setImage(null);setImageError('This image could not be previewed. Please choose another image.')}}}/></div><footer><div><strong>{image?image.name:form.id?'Current banner':'Default banner'}</strong><small>{image?`${(image.size/1024/1024).toFixed(2)} MB · Unsaved image`:'Kept unless you upload a replacement'}</small></div>{image&&<button type="button" disabled={busy} aria-label="Revert banner image" title="Revert image" onClick={()=>{setImage(null);setImageError('')}}><RotateCcw size={16}/></button>}</footer></div>
          </section>
        </div>
      </form>}
    </Modal>
  </div>;
}
