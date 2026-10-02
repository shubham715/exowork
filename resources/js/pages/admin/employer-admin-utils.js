export const statusLabels = {pending: 'Awaiting review', verified: 'Approved', rejected: 'Rejected', inactive: 'Inactive'};
export const dateLabel = value => value ? new Date(value.replace(' ', 'T')).toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric'}) : '—';
export function exportCsv(filename, columns, rows) {
    const cell = value => '"' + String(value ?? '').replace(/^[=+@\-\t\r]/, "'$&").replaceAll('"', '""') + '"';
    const blob = new Blob(['\uFEFF' + [columns.map(c=>cell(c[0])).join(','), ...rows.map(row=>columns.map(c=>cell(row[c[1]])).join(','))].join('\r\n')], {type:'text/csv;charset=utf-8;'});
    const url=URL.createObjectURL(blob), link=document.createElement('a'); link.href=url;link.download=filename;link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
}
export function sortRecords(rows, key, direction) {
    return [...rows].sort((a,b) => (typeof a[key] === 'number' || typeof b[key] === 'number' ? Number(a[key]||0)-Number(b[key]||0) : String(a[key]||'').localeCompare(String(b[key]||''),undefined,{numeric:true,sensitivity:'base'})) * (direction==='asc'?1:-1));
}

export function registrationInRange(value, from, to) {
    const date=String(value||'').slice(0,10);
    return (!from || (date && date>=from)) && (!to || (date && date<=to));
}
