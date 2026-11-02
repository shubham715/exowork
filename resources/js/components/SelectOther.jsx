import React, {useState} from 'react';

// The manual value is sent through the same field as a listed choice, so existing
// search, filters, exports and edit screens all retain the actual location name.
export default function SelectOther({children, value = '', onChange, placeholder, allowOther = true, ...props}) {
    const [manual, setManual] = useState(false);
    const options = React.Children.toArray(children);
    const listed = options.some(option => String(option.props?.value ?? option.props?.children ?? '') === String(value));
    const custom = manual || Boolean(value && !listed);
    const change = next => onChange({target: {name: props.name, value: next}});
    if (!allowOther) return <select {...props} value={value} onChange={onChange}>{children}</select>;
    return <>
        <select {...props} value={custom ? '__manual__' : value} onChange={event => {
            const next = event.target.value;
            setManual(next === '__manual__');
            change(next === '__manual__' ? '' : next);
        }}>
            {children}
            <option value="__manual__">Other — enter manually</option>
        </select>
        {custom && <input name={props.name ? `${props.name}_manual` : undefined} aria-label={`Enter ${props.name || 'value'} manually`} placeholder={placeholder || 'Enter name'} value={value} maxLength={80} required={props.required} disabled={props.disabled} onChange={event => change(event.target.value)} />}
    </>;
}
