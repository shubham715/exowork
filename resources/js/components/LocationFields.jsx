import React, {useEffect, useState} from 'react';
import axios from 'axios';
import SelectOther from './SelectOther.jsx';

export default function LocationFields({data, onChange, required = false}) {
    const [states, setStates] = useState([]), [districts, setDistricts] = useState([]), [error, setError] = useState('');
    useEffect(() => { let active = true; axios.get('/api/master-data').then(({data}) => {if(active)setStates(data.states);}).catch(() => {if(active)setError('Location choices could not be loaded. You can enter a name using Other.');}); return () => {active = false;}; }, []);
    useEffect(() => {
        let active = true;
        setDistricts([]);
        const state = states.find(x => x.name === data.state);
        if (state) axios.get(`/api/master-data/states/${state.slug}/districts`).then(({data}) => {if(active)setDistricts(data.districts);}).catch(() => {if(active)setError('District choices could not be loaded. You can enter a name using Other.');});
        return () => {active = false;};
    }, [states, data.state]);
    return <>{['state', 'district'].map(name => <label className="field-label" key={name}>
        {name === 'state' ? 'State' : 'District'}{required && ' *'}
        <SelectOther name={name} required={required} value={data[name] || ''} disabled={name === 'district' && !data.state} onChange={event => onChange({...data, [name]: event.target.value, ...(name === 'state' ? {district: ''} : {})})}>
            <option value="">Select {name}</option>
            {(name === 'state' ? states : districts).map(x => <option key={x.id} value={x.name}>{x.name}</option>)}
        </SelectOther>
    </label>)}{error && <p role="alert">{error}</p>}</>;
}
