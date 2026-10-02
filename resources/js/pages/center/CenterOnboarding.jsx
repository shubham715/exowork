import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { useNavigate } from 'react-router-dom';
import { ArrowLeft, ArrowRight, Building2, ShieldCheck } from 'lucide-react';
import BrandLogo from '../../components/BrandLogo.jsx';

const initial = { center_type: '', spoc_name: '', spoc_phone: '', spoc_email: '', website: '', state: '', district: '', city_block: '', address: '', pincode: '' };
const types = ['Individual', 'Private', 'Government', 'NGO', 'ITI', 'Polytechnic', 'College', 'Skill Training Center'];

export default function CenterOnboarding() {
  const navigate = useNavigate();
  const [data, setData] = useState(initial);
  const [center, setCenter] = useState(null);
  const [states, setStates] = useState([]);
  const [districts, setDistricts] = useState([]);
  const [errors, setErrors] = useState({});
  const [message, setMessage] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    Promise.all([axios.get('/center-api/onboarding'), axios.get('/api/master-data')])
      .then(([response, master]) => {
        const { center: current, partner } = response.data;
        setCenter(current);
        setData({ ...initial, ...Object.fromEntries(Object.keys(initial).map(key => [key, current[key] ?? ''])),
          spoc_name: current.spoc_name || partner?.authorized_person || '',
          spoc_phone: current.spoc_phone || partner?.phone || '',
          spoc_email: current.spoc_email || partner?.email || '',
        });
        setStates(master.data.states || []);
      }).catch(error => {
        if ([401, 403].includes(error.response?.status)) navigate('/login');
        else setMessage('Your center profile could not be loaded. Please refresh and try again.');
      }).finally(() => setLoading(false));
  }, []);
  useEffect(() => {
    const selected = states.find(state => state.name === data.state);
    if (!selected) { setDistricts([]); return; }
    let active = true;
    axios.get(`/api/master-data/states/${selected.slug}/districts`)
      .then(({ data: response }) => { if (active) setDistricts(response.districts || []); })
      .catch(() => { if (active) setDistricts([]); });
    return () => { active = false; };
  }, [states, data.state]);

  const change = event => {
    const { name, value } = event.target;
    setData(current => ({ ...current, [name]: value, ...(name === 'state' ? { district: '' } : {}) }));
    setErrors(current => ({ ...current, [name]: undefined, ...(name === 'state' ? { district: undefined } : {}) }));
  };
  const save = async (draft = false) => {
    setBusy(true); setMessage(''); setErrors({});
    const meta = document.querySelector('meta[name="csrf-token"]');
    const headers = { 'X-CSRF-TOKEN': meta?.content };
    try {
      await axios.put('/center-api/onboarding', { ...data, step: draft ? 0 : 3, completed_step: draft ? 0 : 2, draft }, { headers });
      if (draft) {
        const response = await axios.post('/auth/logout', {}, { headers });
        if (meta && response.data.csrf_token) meta.content = response.data.csrf_token;
        navigate('/login');
      } else navigate('/center/dashboard');
    } catch (error) {
      setErrors(error.response?.data?.errors || {});
      setMessage(error.response?.status === 422 ? 'Please check the highlighted fields.' : 'Your progress could not be saved. Please try again.');
    } finally { setBusy(false); }
  };
  const field = (name, label, placeholder, options = {}) => <label key={name} className={`form-label reg-field ${options.wide ? 'wide' : ''} ${errors[name] ? 'has-error' : ''}`}>
    <span>{label}{!options.optional && <em>*</em>}</span>
    {name === 'address' ? <textarea name={name} value={data[name]} onChange={change} placeholder={placeholder} rows={3} required />
      : <input name={name} value={data[name]} onChange={change} placeholder={placeholder} type={options.type || 'text'} required={!options.optional} />}
    {errors[name] && <small className="reg-error">{errors[name][0]}</small>}
  </label>;
  const select = (name, label, options, placeholder, disabled = false) => <label className={`form-label reg-field ${errors[name] ? 'has-error' : ''}`}>
    <span>{label}<em>*</em></span><select name={name} value={data[name]} onChange={change} required disabled={disabled}>
      <option value="">{placeholder}</option>{options.map(value => <option key={value} value={value}>{value}</option>)}
    </select>{errors[name] && <small className="reg-error">{errors[name][0]}</small>}
  </label>;

  return <div className="candidate-register public-reg center-public-register">
    <header className="reg-top">
      <button type="button" onClick={() => save(true)} disabled={loading || busy || !center}><ArrowLeft />Save and sign out</button>
      <div className="reg-brand"><BrandLogo /></div><span>{data.spoc_email}</span>
    </header>
    <main>
      <aside className="reg-aside">
        <span className="reg-kicker">CENTER PROFILE SETUP</span>
        <h1>Get your center ready.</h1>
        <p>Register your coaching center, training institute or individual practice with EXOWORK.</p>
        <nav><button type="button" className="active"><span><Building2 /></span><div><b>Center profile</b><small>Contact and location details</small></div></button></nav>
        <div className="privacy-note"><ShieldCheck /><p>You can save and return later. Once registered, add your students as candidates after course completion.</p></div>
      </aside>
      <section className="reg-form">
        <div className="reg-form-head"><span>FINAL STEP / CENTER PROFILE</span><h2>{center?.name || 'Complete your center profile'}</h2><p>Your account details are already filled in. Add your contact person and center address.</p></div>
        {loading ? <div className="center-onboarding-status" role="status">Loading your center profile...</div> : !center ? <div className="center-onboarding-status" role="alert">{message}</div> :
          <form onSubmit={event => { event.preventDefault(); save(); }}>
            <div className="reg-fields">
              {select('center_type', 'Operator type', types, 'Choose operator type')}
              {field('spoc_name', 'Contact person / owner', 'Full name')}
              {field('spoc_phone', 'Contact mobile', '10-digit mobile number', { type: 'tel' })}
              {field('spoc_email', 'Contact email', 'you@example.com', { type: 'email' })}
              {select('state', 'State', states.map(state => state.name), 'Choose a state')}
              {select('district', 'District', districts.map(district => district.name), 'Choose a district', !data.state || !districts.length)}
              {field('city_block', 'City or town', 'City or town')}
              {field('pincode', 'PIN code', '6-digit PIN code')}
              {field('address', 'Center address', 'Building, street and area', { wide: true })}
              {field('website', 'Website (optional)', 'https://example.com', { optional: true, type: 'url', wide: true })}
              {message && <div className="reg-submit-error wide" role="alert">{message}</div>}
            </div>
            <footer className="reg-footer"><span>Save your progress and return any time.</span><div>
              <button className="btn ghost" type="button" disabled={busy} onClick={() => save(true)}>Save and sign out</button>
              <button className="btn primary" type="submit" disabled={busy}>{busy ? 'Saving...' : 'Finish registration'}{!busy && <ArrowRight />}</button>
            </div></footer>
          </form>}
      </section>
    </main>
  </div>;
}
