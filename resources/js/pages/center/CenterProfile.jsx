import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { useNavigate } from 'react-router-dom';
import { Building2, MapPin, Save, ShieldCheck } from 'lucide-react';

const centerTypes = ['Individual', 'Private', 'Government', 'NGO', 'ITI', 'Polytechnic', 'College', 'Skill Training Center'];
const emptyProfile = {
  center_type: '', spoc_name: '', spoc_phone: '', spoc_email: '',
  website: '', state: '', district: '', city_block: '', address: '', pincode: '',
};

function TextField({ label, name, value, onChange, placeholder, required = false, type = 'text', inputMode, error, wide = false, readOnly = false, hint }) {
  return <label className={`center-profile-field${wide ? ' wide' : ''}${error ? ' has-error' : ''}`}>
    <span>{label}{required && <em aria-label="required">*</em>}</span>
    {name === 'address'
      ? <textarea name={name} value={value ?? ''} onChange={onChange} placeholder={placeholder} readOnly={readOnly} rows="3" required={required} />
      : <input name={name} type={type} inputMode={inputMode} value={value ?? ''} onChange={onChange} placeholder={placeholder} readOnly={readOnly} required={required} />}
    {hint && <small>{hint}</small>}
    {error && <small className="center-profile-error">{error}</small>}
  </label>;
}

function SelectField({ label, name, value, onChange, options, placeholder, required = false, disabled = false, error }) {
  return <label className={`center-profile-field${error ? ' has-error' : ''}`}>
    <span>{label}{required && <em aria-label="required">*</em>}</span>
    <select name={name} value={value ?? ''} onChange={onChange} required={required} disabled={disabled}>
      <option value="">{placeholder}</option>
      {options.map(option => <option key={option} value={option}>{option}</option>)}
    </select>
    {error && <small className="center-profile-error">{error}</small>}
  </label>;
}

function Section({ number, title, description, children }) {
  return <section className="center-profile-section">
    <header><span>{number}</span><div><h2>{title}</h2><p>{description}</p></div></header>
    <div className="center-profile-fields">{children}</div>
  </section>;
}

export default function CenterProfile({ onboarding = false }) {
  const navigate = useNavigate();
  const [profile, setProfile] = useState(emptyProfile);
  const [center, setCenter] = useState(null);
  const [states, setStates] = useState([]);
  const [districts, setDistricts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState({});
  const [message, setMessage] = useState('');
  const [saveState, setSaveState] = useState('');

  useEffect(() => {
    Promise.all([axios.get('/center-api/onboarding'), axios.get('/api/master-data')])
      .then(([profileResponse, masterResponse]) => {
        const current = profileResponse.data.center;
        setCenter(current);
        setProfile({
          ...emptyProfile,
          ...Object.fromEntries(Object.keys(emptyProfile).map(key => [key, current[key] ?? ''])),
          spoc_name: current.spoc_name || profileResponse.data.partner?.authorized_person || '',
          spoc_phone: current.spoc_phone || profileResponse.data.partner?.phone || '',
          spoc_email: current.spoc_email || profileResponse.data.partner?.email || '',
        });
        setStates(masterResponse.data.states || []);
      })
      .catch(error => {
        if (onboarding && [401, 403].includes(error.response?.status)) navigate('/login');
        else setMessage('The center profile could not be loaded. Refresh the page and try again.');
      })
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    const selected = states.find(state => state.name === profile.state);
    if (!selected) { setDistricts([]); return; }
    axios.get(`/api/master-data/states/${selected.slug}/districts`)
      .then(({ data }) => setDistricts(data.districts || []))
      .catch(() => setDistricts([]));
  }, [states, profile.state]);

  const change = event => {
    const { name, value } = event.target;
    setProfile(current => ({ ...current, [name]: value, ...(name === 'state' ? { district: '' } : {}) }));
    setErrors(current => ({ ...current, [name]: undefined }));
    setSaveState('');
  };
  const save = async (event, draft = false) => {
    event.preventDefault();
    setSaving(true);
    setMessage('');
    setErrors({});
    setSaveState('');
    try {
      await axios.put('/center-api/onboarding', { ...profile, step: draft ? 0 : 3, completed_step: draft ? 0 : 2, draft }, {
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content },
      });
      if (draft) {
        const response = await axios.post('/auth/logout', {}, { headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content } });
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && response.data.csrf_token) meta.content = response.data.csrf_token;
        navigate('/login');
      } else if (onboarding) {
        navigate('/center/dashboard');
      } else {
        setSaveState('Your center profile was saved.');
      }
    } catch (error) {
      setErrors(error.response?.data?.errors || {});
      setMessage(error.response?.data?.message || 'The profile could not be saved. Please try again.');
    } finally {
      setSaving(false);
    }
  };
  const field = (name, label, placeholder, options = {}) =>
    <TextField key={name} name={name} label={label} placeholder={placeholder} value={profile[name]} onChange={change} error={errors[name]?.[0]} {...options} />;
  const verificationLabel = center?.status === 'verified' ? 'Verified center' : center?.status === 'rejected' ? 'Changes required' : 'Review pending';

  return <div className="center-profile-page">
    <div className="center-profile-heading">
      <span>CENTER PROFILE</span>
      <h1>{onboarding ? 'Complete your center registration' : 'Center profile and verification'}</h1>
      <p>{onboarding ? 'One short profile for your coaching center, training institute or individual practice. Add your students as candidates after course completion.' : 'Keep your contact and location details accurate.'}</p>
    </div>
    {loading ? <div className="center-profile-loading">Loading center profile…</div> : !center
      ? <div className="center-profile-loading" role="alert">{message}</div>
      : <div className="center-profile-layout">
        <form className="center-profile-form" onSubmit={save}>
          <div className="center-profile-form-head">
            <span>PROFILE DETAILS</span>
            <h2>{center.name}</h2>
            <p>Fields marked <em>*</em> are required. Your account details are already filled in. Update them only if needed.</p>
          </div>
          <Section number="01" title="Center identity" description="Details used to identify your training center.">
            <TextField label="Center name" value={center.name} readOnly hint="Contact support if the registered center name needs correction." />
            {!onboarding && <TextField label="Center code" value={center.code} readOnly />}
            <SelectField name="center_type" label="Operator type" value={profile.center_type} onChange={change} options={centerTypes} placeholder="Choose operator type" required error={errors.center_type?.[0]} />
          </Section>
          <Section number="02" title="Contact details" description="Who should we contact about this center?">
            {field('spoc_name', 'Contact person / owner', 'Enter full name', { required: true })}
            {field('spoc_phone', 'Center contact mobile', '10-digit mobile number', { required: true, type: 'tel' })}
            {field('spoc_email', 'Center email', 'center@example.org', { required: true, type: 'email' })}
            {field('website', 'Website', 'https://example.org', { type: 'url' })}
          </Section>
          <Section number="03" title="Center location" description="Select your state and district, then add the center address.">
            <SelectField name="state" label="State" value={profile.state} onChange={change} options={states.map(item => item.name)} placeholder="Choose a state" required error={errors.state?.[0]} />
            <SelectField name="district" label="District" value={profile.district} onChange={change} options={districts.map(item => item.name)} placeholder={profile.state ? 'Choose a district' : 'Select a state first'} disabled={!profile.state || !districts.length} required error={errors.district?.[0]} />
            {field('city_block', 'City or town', 'Enter city, town, or block', { required: true })}
            {field('pincode', 'PIN code', '6-digit PIN code', { required: true, inputMode: 'numeric' })}
            {field('address', 'Full address', 'Building, street, area, and landmark', { required: true, wide: true })}
          </Section>
          {message && <p className="center-profile-notice error" role="alert">{message}</p>}
          {saveState && <p className="center-profile-notice success" role="status">{saveState}</p>}
          <footer className="center-profile-actions">
            <span>{onboarding ? 'You can save and return later.' : 'Changes are saved to your center profile.'}</span>
            <div className="center-profile-action-buttons">
              {onboarding && <button className="btn ghost" type="button" disabled={saving} onClick={event => save(event, true)}>Save and sign out</button>}
              <button className="btn primary" type="submit" disabled={saving}><Save />{saving ? 'Saving...' : onboarding ? 'Finish registration' : 'Save changes'}</button>
            </div>
          </footer>
        </form>
        <aside className="center-profile-aside">
          <div className="center-profile-aside-icon"><Building2 /></div>
          <span>CENTER RECORD</span>
          <h2>{center.name}</h2>
          <p>{center.code}</p>
          <div className={`center-profile-status${center.status === 'verified' ? ' is-active' : ''}`}><ShieldCheck /><div><strong>{verificationLabel}</strong><small>{center.status === 'verified' ? 'Your center profile has been approved.' : 'Your center details are awaiting review.'}</small></div></div>
          <div className="center-profile-aside-note"><MapPin /><p>Register your students as candidates after they complete their courses.</p></div>
        </aside>
      </div>}
  </div>;
}
