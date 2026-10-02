import React,{useEffect,useMemo,useRef,useState}from'react';

import{useNavigate}from'react-router-dom';



import{CheckCircle2,Clock3,Copy,Download,Mail,Send,Upload,FileWarning,Plus,Search,ShieldCheck,UserRound,Users}from'lucide-react';



import{Modal,PageHead,Status}from'../../components/UI';



import axios from 'axios';



import './center-candidates.css';



const candidateReadiness=c=>c.readiness;







export default function CenterCandidates(){



  const nav=useNavigate(),[candidates,setCandidates]=useState([]),[batches,setBatches]=useState([]),[query,setQuery]=useState(''),[filter,setFilter]=useState('All'),[detail,setDetail]=useState(null),[loading,setLoading]=useState(true),[error,setError]=useState(''),[page,setPage]=useState(1),[batchFilter,setBatchFilter]=useState('');



  const load=()=>{setLoading(true);setError('');axios.get('/center-api/candidates').then(({data})=>{setCandidates(data.candidates);setBatches(data.batches)}).catch(e=>{if(e.response?.status===401)nav('/login',{replace:true});else setError(e.response?.data?.message||'Candidates could not be loaded. Please try again.')}).finally(()=>setLoading(false))};



  useEffect(()=>{load()},[]);



  useEffect(()=>{setPage(1)},[query,filter,batchFilter]);



  const rows=useMemo(()=>candidates.filter(c=>{const readiness=candidateReadiness(c);return(!batchFilter||c.batch===batchFilter)&&(filter==='All'||readiness===filter)&&[c.id,c.firstName,c.lastName,c.whatsapp,c.batch,c.skills,c.industry].join(' ').toLowerCase().includes(query.toLowerCase())}),[candidates,query,filter,batchFilter]);



  const pages=Math.max(1,Math.ceil(rows.length/10)),currentPage=Math.min(page,pages);



  const jobReady=candidates.filter(c=>candidateReadiness(c)==='Job ready').length,ending=candidates.filter(c=>candidateReadiness(c)==='Eligible soon').length,incomplete=candidates.filter(c=>(c.profile||0)<80||!c.consent).length;



  const [importOpen,setImportOpen]=useState(false),[file,setFile]=useState(null),[preview,setPreview]=useState(null),[busy,setBusy]=useState(false),[operationError,setOperationError]=useState(''),[inviteUrl,setInviteUrl]=useState(''),[editing,setEditing]=useState(null),[draggingFile,setDraggingFile]=useState(false);

  const importFileInput=useRef(null);

  const [inviteOpen,setInviteOpen]=useState(false),[inviteEmail,setInviteEmail]=useState(''),[inviteBusy,setInviteBusy]=useState(false),[inviteError,setInviteError]=useState(''),[inviteNotice,setInviteNotice]=useState('');

  const exportRows=()=>{window.location.href='/center-api/candidates/csv'};



  const previewCsv=async()=>{setBusy(true);setOperationError('');setPreview(null);try{const form=new FormData();form.append('file',file);const r=await axios.post('/center-api/candidates/import/preview',form);setPreview(r.data)}catch(e){setOperationError(e.response?.data?.message||'Could not validate CSV.')}finally{setBusy(false)}};



  const confirmCsv=async()=>{setBusy(true);setOperationError('');try{await axios.post('/center-api/candidates/import/confirm',{token:preview.token});setImportOpen(false);setPreview(null);setFile(null);load()}catch(e){setOperationError(e.response?.data?.message||'Import failed. Preview the CSV again.')}finally{setBusy(false)}};



  const shareInvite=async()=>{setInviteOpen(true);setInviteEmail('');setInviteNotice('');setInviteError('');setInviteUrl('');try{const r=await axios.get('/center-api/candidates/invite');setInviteUrl(r.data.url)}catch(e){setInviteError(e.response?.data?.message||'Could not generate invitation.')}};

  const sendInvite=async e=>{e.preventDefault();setInviteBusy(true);setInviteError('');setInviteNotice('');try{const r=await axios.post('/center-api/candidates/invite',{email:inviteEmail.trim()});setInviteNotice(r.data.message)}catch(e){setInviteError(e.response?.data?.errors?.email?.[0]||e.response?.data?.message||'Could not send invitation.')}finally{setInviteBusy(false)}};

  const copyInvite=async()=>{setInviteError('');setInviteNotice('');try{await navigator.clipboard.writeText(inviteUrl);setInviteNotice('Link copied. Share it with your candidates.')}catch{setInviteError('Select and copy the invitation link below.')}};

  const editCandidate=async c=>{setOperationError('');try{const r=await axios.get(`/center-api/candidates/${c.id}/edit`);setEditing(r.data.candidate)}catch(e){setError(e.response?.data?.message||'Could not load candidate.')}};



  const saveEdit=async()=>{setBusy(true);setOperationError('');try{await axios.patch(`/center-api/candidates/${editing.candidate_code}/edit`,editing);setEditing(null);setDetail(null);load()}catch(e){setOperationError(Object.values(e.response?.data?.errors||{}).flat().join(' ')||e.response?.data?.message||'Update failed.')}finally{setBusy(false)}};



  return <div className="center-candidates-page">



    <PageHead kicker="CANDIDATE OPERATIONS" title="Center candidates" sub="Register complete candidate profiles, optionally assign learners to saved batches and track placement readiness.">



      <button className="btn ghost" onClick={shareInvite}><Mail/>Share invite link</button><details className="cc-import-menu"><summary className="btn ghost">Import/Export</summary><div className="cc-import-menu-list"><a href="/center-api/candidates/csv?sample=1" download><Download/>Download CSV template</a><button onClick={e=>{e.currentTarget.closest('details').open=false;setImportOpen(true);setPreview(null);setOperationError('')}}><Upload/>Import candidates</button><button disabled={loading||!!error||!rows.length} onClick={e=>{e.currentTarget.closest('details').open=false;exportRows()}}><Download/>Export candidates</button></div></details><button className="btn primary" onClick={()=>nav('/center/candidates/new')}><Plus/>Register candidate</button>

    </PageHead>



    {error&&<div className="cc-message" role="alert">{error}<button className="btn ghost" onClick={load}>Try again</button></div>}



    <div className="workspace-stats"><article><Users/><span>Total candidates<b>{candidates.length}</b></span></article><article><CheckCircle2/><span>Job ready<b>{jobReady}</b></span></article><article><Clock3/><span>Eligible soon<b>{ending}</b></span></article><article><FileWarning/><span>Profiles needing action<b>{incomplete}</b></span></article></div>



    <section className="workspace-card" aria-busy={loading}><div className="candidate-tabs">{['All','In training','Eligible soon','Job ready','Not available'].map(x=><button className={filter===x?'active':''} onClick={()=>setFilter(x)} key={x}>{x}</button>)}</div><div className="workspace-toolbar"><div className="search-box"><Search/><input value={query} onChange={e=>setQuery(e.target.value)} placeholder="Search name, EXOWORK ID, mobile, skill or batch…"/></div><select aria-label="Filter by training batch" value={batchFilter} onChange={e=>setBatchFilter(e.target.value)}><option value="">All batches</option>{batches.map(b=><option key={b.code} value={b.code}>{b.code}</option>)}</select><button className="btn ghost" onClick={load} disabled={loading}>Refresh</button></div>



      <div className="candidate-table table-scroll data-table"><table><thead><tr><th>Candidate</th><th>Contact & location</th><th>Training batch</th><th>Profile</th><th>Placement readiness</th><th>Actions</th></tr></thead><tbody>{!loading&&!error&&rows.slice((currentPage-1)*10,currentPage*10).map(c=>{const batch=batches.find(b=>b.code===c.batch),readiness=candidateReadiness(c);return <tr key={c.id}><td><div className="candidate-name"><span>{c.firstName?.[0]}{c.lastName?.[0]}</span><div><button className="cc-name-button table-record-link" onClick={()=>setDetail(c)}>{c.firstName} {c.lastName}</button><small>{c.id}</small></div></div></td><td><b>{mask(c.whatsapp)}</b><small>{c.district||'Location incomplete'} · {c.qualification||'Qualification incomplete'}</small></td><td><b>{c.batch||'No batch assigned'}</b><small>{batch?`${batch.jobRole} · ends ${formatDate(batch.endDate)}`:'Batch can be assigned later'}</small></td><td><b>{c.profile||calculateProfile(c)}%</b><div className="mini-progress"><i style={{width:`${c.profile||calculateProfile(c)}%`}}/></div><small>{c.consent?'Consent active':'Consent missing'}</small></td><td><Status tone={readiness.toLowerCase().replaceAll(' ','-')}>{readiness}</Status><small>{c.available}</small></td><td><button className="btn ghost" aria-label={`Edit ${c.firstName} ${c.lastName}`} onClick={()=>editCandidate(c)}>Edit</button></td></tr>})}</tbody></table>{loading?<div className="workspace-empty" role="status">Loading candidates…</div>:!error&&!rows.length&&<div className="workspace-empty"><Users/><h3>{candidates.length?'No matching candidates':'Your candidate directory starts here'}</h3><p>{candidates.length?'Try another search or filter.':'Register your first candidate to track training and placement readiness.'}</p><button className="btn primary" onClick={()=>candidates.length?(setQuery(''),setFilter('All'),setBatchFilter('')):nav('/center/candidates/new')}>{candidates.length?'Clear filters':'Register candidate'}</button></div>}</div>



    <div className="cc-pagination"><span>{loading?'Loading…':`${rows.length} candidates · Page ${currentPage} of ${pages}`}</span><div><button className="btn ghost" disabled={currentPage===1||loading} onClick={()=>setPage(currentPage-1)}>Previous</button><button className="btn ghost" disabled={currentPage>=pages||loading} onClick={()=>setPage(currentPage+1)}>Next</button></div></div></section>



    <Modal open={importOpen} onClose={()=>{if(!busy)setImportOpen(false)}} title="Bulk import candidates" className={preview?'cc-import-modal is-preview':'cc-import-modal'} sub={preview?'Review the validated rows, then confirm the import.':'Choose your CSV file and validate it before importing.'}>



      {!preview&&<>

      <div className="cc-upload-heading"><span className="cc-upload-label">Upload CSV</span><a className="btn ghost cc-sample-download" href="/center-api/candidates/csv?sample=1"><Download/>Download CSV template</a></div>

      <p className="cc-import-help">Leave candidate_code blank to add a student; separate languages and preferred locations with semicolons; leave batch_code blank or use a batch saved by your center.</p>

      <div className="cc-upload">

        <input ref={importFileInput} className="cc-file-input" type="file" accept=".csv,text/csv" disabled={busy} onChange={e=>{const selected=e.target.files?.[0];if(selected){setFile(selected);setPreview(null);setOperationError('')}e.target.value=''}}/>

        {file?<div className="cc-selected-file" role="status"><span className="cc-selected-file-type">{file.name.split('.').pop()?.toUpperCase()||'FILE'}</span><div><strong title={file.name}>{file.name}</strong><small>{file.size<1024?`${file.size} B`:`${(file.size/1024).toFixed(1)} KB`} · Ready to validate</small></div><button type="button" className="btn ghost" disabled={busy} onClick={()=>importFileInput.current?.click()}>Change file</button></div>:<button type="button" className={`cc-dropzone${draggingFile?' is-dragging':''}`} disabled={busy} onClick={()=>importFileInput.current?.click()} onDragOver={e=>{e.preventDefault();if(!busy)setDraggingFile(true)}} onDragLeave={e=>{if(e.currentTarget===e.target)setDraggingFile(false)}} onDrop={e=>{e.preventDefault();setDraggingFile(false);if(busy)return;const dropped=e.dataTransfer.files?.[0];if(dropped){setFile(dropped);setPreview(null);setOperationError('')}}} aria-label="Choose a CSV file or drop it here">

          <span className="cc-dropzone-icon"><Upload/></span><span className="cc-dropzone-copy"><strong>Choose a CSV file or drag it here</strong><small>Click to browse · CSV files only</small></span><span className="cc-dropzone-browse">Browse files</span>

        </button>}

      </div>

      <button className="btn primary" disabled={!file||busy} onClick={previewCsv}>{busy?'Processing...':'Validate and preview'}</button>

      </>}



      {operationError&&<div className="cc-import-notice is-error" role="alert"><strong>Unable to continue</strong><span>{operationError}</span></div>}

      {preview&&<><div className="cc-preview-toolbar"><button type="button" className="btn ghost" disabled={busy} onClick={()=>{setPreview(null);setOperationError('')}}>Back to file</button><span title={file?.name}>{file?.name}</span></div><div className="cc-import-stats"><b>Total: {preview.total}</b><b>Add: {preview.add}</b><b>Update: {preview.update}</b><b>Invalid: {preview.invalid}</b></div><div className="cc-import-notice"><strong>Preview only</strong><span>{preview.invalid?'No data has been imported yet. Go back to replace the file after correcting invalid rows.':'All rows are valid. Confirm below to save them to your center.'}</span></div><div className="table-scroll cc-import-preview"><table><thead><tr><th>Row</th><th>Candidate</th><th>Action</th><th>Validation / warnings</th></tr></thead><tbody>{preview.rows.map(r=><tr key={r.row}><td>{r.row}</td><td>{r.name}</td><td>{r.action}</td><td>{[...r.errors,...r.warnings].map((message,i)=><span className={`cc-validation-badge${r.errors.length?' is-error':''}`} key={i}>{message}</span>)}{!r.errors.length&&!r.warnings.length&&<span className="cc-validation-badge is-valid">Valid</span>}</td></tr>)}</tbody></table></div><button className="btn primary" disabled={busy||preview.invalid>0} onClick={confirmCsv}>Confirm import {preview.add+preview.update} candidates</button></>}

    </Modal>



    <Modal open={inviteOpen} onClose={()=>{if(!inviteBusy)setInviteOpen(false)}} title="Invite a candidate" sub="Send a registration invitation or share your center's link.">

      <div className="cc-invite-summary"><span><Users/></span><div><b>A direct path to your training center</b><p>Candidates automatically join your center with ongoing training. They choose their batch during registration.</p></div></div>

      <form className="cc-invite-form" onSubmit={sendInvite}><label htmlFor="cc-invite-email">Candidate email address</label><div className="cc-email-field"><Mail/><input id="cc-invite-email" type="email" autoComplete="email" autoFocus required maxLength={255} placeholder="candidate@example.com" value={inviteEmail} disabled={inviteBusy} onChange={e=>{setInviteEmail(e.target.value);setInviteError('');setInviteNotice('')}}/></div><p>We'll email a registration invitation with your training center's name and a secure link.</p><button type="submit" className="btn primary cc-send-invite" disabled={inviteBusy||!inviteUrl||!inviteEmail.trim()}><Send/>{inviteBusy?'Sending invitation...':'Send email invitation'}</button></form>

      <div className="cc-invite-divider"><span>or share a link</span></div><label className="cc-invite-link-label" htmlFor="cc-invite-url">Your center's registration link</label><div className="cc-invite-link"><input id="cc-invite-url" readOnly value={inviteUrl} placeholder="Preparing your link..." onFocus={e=>e.target.select()}/><button type="button" className="btn ghost" disabled={!inviteUrl} onClick={copyInvite}><Copy/>Copy link</button></div><p className="cc-invite-hint">Share by WhatsApp, SMS or any channel you prefer.</p>

      {inviteError&&<div className="cc-invite-feedback error" role="alert">{inviteError}</div>}{inviteNotice&&<div className="cc-invite-feedback success" role="status"><CheckCircle2/>{inviteNotice}</div>}

    </Modal>

    <Modal open={!!editing} onClose={()=>{if(!busy)setEditing(null)}} title="Edit candidate" sub={editing?.candidate_code}>{editing&&<><div className="cc-edit-grid">{Object.entries(editing).filter(([key])=>key!=='candidate_code').map(([key,value])=><label key={key}><span>{key.replaceAll('_',' ')}</span>{key==='batch_code'?<select value={value} onChange={e=>setEditing(d=>({...d,[key]:e.target.value}))}><option value="">No batch assigned</option>{batches.map(b=><option key={b.code} value={b.code}>{b.code}</option>)}</select>:key==='training_status'||key==='availability'?<select value={value} onChange={e=>setEditing(d=>({...d,[key]:e.target.value}))}>{(key==='training_status'?['Not enrolled','Ongoing','Completed']:['Yes','No','Available after training']).map(v=><option key={v}>{v}</option>)}</select>:<input value={value} onChange={e=>setEditing(d=>({...d,[key]:e.target.value}))}/>}</label>)}</div><p>Separate languages and preferred locations with semicolons.</p>{operationError&&<p role="alert">{operationError}</p>}<button className="btn primary" disabled={busy} onClick={saveEdit}>{busy?'Saving...':'Save changes'}</button></>}</Modal>



    <Modal open={!!detail} onClose={()=>setDetail(null)} title={detail?`${detail.firstName} ${detail.lastName}`:''} sub={detail?.id}>{detail&&<CandidateDetail candidate={detail} batch={batches.find(b=>b.code===detail.batch)}/>}</Modal>



  </div>;



}



function CandidateDetail({candidate:c,batch}){return <><div className="candidate-detail-head"><span><UserRound/></span><div><Status tone={candidateReadiness(c).toLowerCase().replaceAll(' ','-')}>{candidateReadiness(c)}</Status><h3>{c.firstName} {c.lastName}</h3><p>{mask(c.whatsapp)} · {c.email||'No email'} · {c.district||'Location incomplete'}</p></div></div><div className="detail-sections"><section><h4>Personal & employability</h4><Info label="Qualification" value={c.qualification}/><Info label="Skills" value={c.skills}/><Info label="Experience" value={c.experienceType==='Experienced'?c.experience:'Fresher'}/><Info label="Preferred industry" value={c.industry}/><Info label="Expected salary" value={c.salary?`₹${c.salary}`:''}/><Info label="Relocation" value={c.relocate}/></section><section><h4>Training & readiness</h4><Info label="Actual batch" value={c.batch}/><Info label="Job role" value={batch?.jobRole}/><Info label="Batch end" value={formatDate(c.batchEnd||batch?.endDate)}/><Info label="Training status" value={c.trainingStatus}/><Info label="Available for job" value={c.available}/></section></div><div className="consent-banner"><ShieldCheck/><div><b>Consent evidence {c.consent?'recorded':'missing'}</b><p>Source: {c.source||'Not recorded'} · Notice {c.consentVersion||'Not recorded'} · {c.consentRecordedAt?new Date(c.consentRecordedAt).toLocaleString('en-IN'):'Acceptance not recorded'}</p></div></div></>}



function Info({label,value}){return <div className="info-row"><span>{label}</span><b>{value||'Not provided'}</b></div>}



function mask(v=''){return v.length>=4?`${v.slice(0,2)}••• ••${v.slice(-3)}`:v||'No mobile'}



function formatDate(value){return value?new Date(value+'T00:00:00').toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}):'Not available'}



function calculateProfile(c){const fields=['firstName','lastName','whatsapp','district','qualification','skills','industry','salary','batch','batchEnd'];return Math.round(fields.filter(k=>c[k]).length/fields.length*100)}



