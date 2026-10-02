import assert from 'node:assert/strict';

import {test} from 'node:test';

import {readFile} from 'node:fs/promises';

import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';

import {build} from 'esbuild';

import React from 'react';

import {renderToStaticMarkup} from 'react-dom/server';

import {MemoryRouter} from 'react-router-dom';



async function renderForPermission(canManage, fixture=null) {

 const result=await build({absWorkingDir:fileURLToPath(new URL('../../',import.meta.url)),entryPoints:['resources/js/pages/admin/EmployerOrganizations.jsx'],bundle:true,write:false,platform:'node',format:'cjs',loader:{'.css':'empty'},external:['react','react-dom','react-router-dom','axios','lucide-react'],plugins:[{name:'authorized-admin-fixture',setup(builder){builder.onLoad({filter:/EmployerOrganizations\.jsx$/},async({path})=>({loader:'jsx',contents:(await readFile(path,'utf8')).replace('[canManage,setCanManage]=useState(false)',`[canManage,setCanManage]=useState(${canManage})`).replace('[success,setSuccess]=useState(null)',fixture?.success?`[success,setSuccess]=useState(${JSON.stringify(fixture.success)})`:'[success,setSuccess]=useState(null)').replace('[action,setAction]=useState(null)',fixture?.action?`[action,setAction]=useState(${JSON.stringify(fixture.action)})`:'[action,setAction]=useState(null)').replace('[actionIds,setActionIds]=useState([])',fixture?.action?`[actionIds,setActionIds]=useState([1])`:'[actionIds,setActionIds]=useState([])').replace('[selected,setSelected]=useState(null)',fixture?.selected?`[selected,setSelected]=useState(${JSON.stringify(fixture.selected)})`:'[selected,setSelected]=useState(null)').replace("[mode,setMode]=useState('view')",fixture?.edit?"[mode,setMode]=useState('edit')":"[mode,setMode]=useState('view')").replace('[rows,setRows]=useState([])',fixture?`[rows,setRows]=useState(${JSON.stringify(fixture.rows)})`:'[rows,setRows]=useState([])').replace('[loading,setLoading]=useState(true)',fixture?'[loading,setLoading]=useState(false)':'[loading,setLoading]=useState(true)').replace('[meta,setMeta]=useState({total:0,current_page:1,last_page:1,from:null,to:null})',fixture?`[meta,setMeta]=useState(${JSON.stringify(fixture.meta)})`:'[meta,setMeta]=useState({total:0,current_page:1,last_page:1,from:null,to:null})').replace('[counts,setCounts]=useState({all:0})',fixture?'[counts,setCounts]=useState({all:1005,verified:1005})':'[counts,setCounts]=useState({all:0})')}));}}]});

 const module={exports:{}};

 new Function('require','module','exports',result.outputFiles[0].text)(createRequire(import.meta.url),module,module.exports);

 const html=renderToStaticMarkup(React.createElement(MemoryRouter,null,React.createElement(module.exports.default)));
 assert.doesNotMatch(html,/\uFFFD/,'Employer UI must not contain replacement characters');
 return html;

}

for(const canManage of [true,false]) test(`employers render with management permission ${canManage} and no selected profile`,async()=>{

 const html=await renderForPermission(canManage);

 assert.match(html,/Manage company profiles/);

 assert.match(html,/Search employers/);

 assert.match(html,/Filters/);

 assert.doesNotMatch(html,/role="dialog"/);

});



test('registration date range includes both days and excludes adjacent days',async()=>{

 const {registrationInRange}=await import('../../resources/js/pages/admin/employer-admin-utils.js');

 assert.ok(registrationInRange('2026-10-02 00:00:00','2026-10-02','2026-10-03'));

 assert.ok(registrationInRange('2026-10-03 23:59:59','2026-10-02','2026-10-03'));

 assert.ok(!registrationInRange('2026-10-01 23:59:59','2026-10-02','2026-10-03'));

 assert.ok(!registrationInRange('2026-10-04 00:00:00','2026-10-02','2026-10-03'));

 assert.ok(!registrationInRange(null,'2026-10-02',''));

 assert.ok(registrationInRange(null,'',''));

});



test('paginated page renders server totals with only the supplied 25 records',async()=>{

 const rows=Array.from({length:25},(_,i)=>({id:i+1,name:`Company${i}`,legal_name:`Company${i}`,code:`EMP${i}`,status:'verified',industry:'Retail',jobs_count:0,hires_count:0,created_at:'2026-10-02 00:00:00'}));

 const html=await renderForPermission(true,{rows,meta:{total:1005,current_page:1,last_page:41,from:1,to:25}});

 assert.equal((html.match(/aria-label="Select Company/g)||[]).length,25);

 assert.match(html,/1 - 25 of 1005/);

 assert.match(html,/Page 1 of 41/);

 assert.match(html,/1005/);

});



const profileFixture={id:1,name:'Fixture company',legal_name:'Fixture company Ltd',code:'FIXTURE',status:'pending',industry:'Retail',document_name:'company-proof.png',document_type:'png',document_url:'/admin-api/employers/1/document',jobs_count:3,hires_count:1};

const metadata={total:1,current_page:1,last_page:1,from:1,to:1};

test('pending profiles have accessible approval and rejection icon actions with tooltips',async()=>{

 const html=await renderForPermission(true,{rows:[profileFixture],meta:metadata});

 assert.match(html,/aria-label="Approve Fixture company Ltd"/);

 assert.match(html,/aria-label="Reject Fixture company Ltd"/);

 assert.match(html,/data-tooltip="Approve profile"/);

 assert.match(html,/data-tooltip="Reject profile"/);

});

test('view dialog displays an image document preview and separate company and contact sections',async()=>{

 const html=await renderForPermission(true,{rows:[profileFixture],meta:metadata,selected:profileFixture});

 assert.match(html,/src="\/admin-api\/employers\/1\/document\?preview=1"/);

 assert.match(html,/target="_blank"/);

 assert.match(html,/Company details/);

 assert.match(html,/Registered address/);

 assert.match(html,/Primary contact/);

 assert.match(html,/Download proof/);

});

test('edit dialog retains the document preview and PDF documents use a file icon',async()=>{

 const profile={...profileFixture,document_name:'proof.pdf',document_type:'pdf'};

 const html=await renderForPermission(true,{rows:[profile],meta:metadata,selected:profile,edit:true});

 assert.match(html,/Edit employer profile/);

 assert.match(html,/PDF document/);

 assert.match(html,/lucide-file-text/);

 assert.doesNotMatch(html,/alt="Employer verification document"/);

 assert.match(html,/Save profile/);

});


test('successful approvals show a styled accessible confirmation dialog with icon and profile count',async()=>{
 const html=await renderForPermission(true,{rows:[],meta:metadata,success:{action:'approve',title:'Employers approved',message:'2 employers can now publish jobs.',count:2}});
 assert.match(html,/role="dialog"/);
 assert.match(html,/ea-success-dialog/);
 assert.match(html,/ea-success-icon/);
 assert.match(html,/2 profiles updated successfully/);
 assert.match(html,/Done/);
 assert.doesNotMatch(html,/role="alert"/);
});
test('manual approval confirmation does not block incomplete profiles or claim missing proof is verified',async()=>{
 const html=await renderForPermission(true,{rows:[{...profileFixture,document_url:null}],meta:metadata,action:'approve'});
 assert.match(html,/Approve the selected profiles as an administrator/);
 assert.doesNotMatch(html,/must have complete verification/);
 assert.doesNotMatch(html,/role="alert"/);
});
