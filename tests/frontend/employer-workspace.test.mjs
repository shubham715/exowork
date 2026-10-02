import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { build } from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';

const result = await build({entryPoints:['resources/js/pages/employer/EmployerWorkspace.jsx'],bundle:true,write:false,platform:'node',format:'cjs',loader:{'.css':'empty'},external:['react','react-dom','react-router-dom','axios','lucide-react'],plugins:[{name:'fixture',setup(builder){builder.onLoad({filter:/EmployerWorkspace\.jsx$/},async({path})=>{let s=await readFile(path,'utf8');const start=s.indexOf('export function useEmployerWorkspace()'),end=s.indexOf('export default function EmployerWorkspace',start);return {loader:'jsx',contents:s.slice(0,start)+'export function useEmployerWorkspace(){return globalThis.employerFixture;}\n'+s.slice(end)};});}}]});
const module={exports:{}};
new Function('require','module','exports',result.outputFiles[0].text)(createRequire(import.meta.url),module,module.exports);
const Workspace=module.exports.default;
const data={employer:{name:'Fixture company',code:'TEST',status:'pending'},jobs:[],interviews:[],placements:[],summary:{active_jobs:0,draft_jobs:0,candidates:0,interviews:0,joined:0}};
function render(page,fixture=data,path='/employer/dashboard'){
 globalThis.employerFixture={data:fixture,loading:false,error:'',setData(){},setError(){},load(){}};
 return renderToStaticMarkup(React.createElement(MemoryRouter,{initialEntries:[path]},React.createElement(Workspace,{page})));
}
test('dashboard exposes useful hiring tasks without claiming pending verification is approved',()=>{const html=render('dashboard');assert.match(html,/Jobs and hiring/);assert.match(html,/Next interviews/);assert.match(html,/Pending company verification/);assert.doesNotMatch(html,/Company verified/);});

test('approved employer can create a job without an approval warning',()=>{const html=render('jobs/new',{...data,employer:{...data.employer,status:'verified'}},'/employer/jobs/new');assert.match(html,/Create job requirement/);assert.match(html,/>Verified</);assert.doesNotMatch(html,/Approval needed to publish|Pending verification|Company approval is required/);});

test('pending employer still sees the publishing approval requirement',()=>{const html=render('jobs/new',data,'/employer/jobs/new');assert.match(html,/Pending verification/);assert.match(html,/Approval needed to publish/);});
test('bulk review bounds a large released pool to twenty visible rows',()=>{const interviews=Array.from({length:55},(_,i)=>({id:i+1,application_id:i+1,job_post_id:1,candidate_id:i+1,first_name:`Learner${i+1}`,last_name:'Test',title:'Operator',status:'scheduled',scheduled_at:'2026-10-03T10:00:00+05:30'}));const html=render('talent',{...data,interviews});assert.match(html,/55 records/);assert.match(html,/Page 1 of 3/);assert.equal((html.match(/aria-label="Select Learner/g)||[]).length,20);assert.doesNotMatch(html,/Learner21/);});
test('job filter uses record IDs even when titles are identical',()=>{const interviews=[1,2].map(id=>({id,application_id:id,job_post_id:id,candidate_id:id,first_name:`Learner${id}`,last_name:'Test',title:'Operator',status:'scheduled'}));const html=render('talent',{...data,interviews},'/employer/talent?job=2');assert.match(html,/Learner2/);assert.doesNotMatch(html,/Learner1/);});
test('candidate pool preserves distinct jobs and uses the latest interview per application',()=>{const interviews=[{id:1,application_id:1,first_name:'Older',last_name:'Test',title:'Operator',status:'scheduled'},{id:2,application_id:1,first_name:'Latest',last_name:'Test',title:'Operator',status:'confirmed'},{id:3,application_id:2,first_name:'Other',last_name:'Test',title:'Assistant',status:'scheduled'}];const html=render('talent',{...data,interviews});assert.match(html,/2 records/);assert.match(html,/Latest/);assert.doesNotMatch(html,/Older/);assert.match(html,/Other/);});

test('released candidate records expose the WhatsApp send action',()=>{const interviews=[{id:1,application_id:1,job_post_id:1,candidate_code:'EXO-CAN-000001',first_name:'Asha',last_name:'Sharma',title:'Operator',status:'scheduled'}];const html=render('talent',{...data,interviews});assert.match(html,/Asha/);assert.match(html,/Send WhatsApp/);assert.match(html,/whatsapp-button/);});
test('filtered jobs provide a clear empty state',()=>{const html=render('jobs',{...data,jobs:[{id:1,title:'Operator',status:'active'}]},'/employer/jobs?status=draft');assert.match(html,/match your filters/);});

test('job cards show recorded metrics and remaining days',()=>{const html=render('jobs',{...data,jobs:[{id:1,title:'Operator',status:'active',openings:2,applications_count:12,views_count:30,clicks_count:5,days_remaining:8,application_deadline:'2026-10-30'}]});assert.match(html,/Impressions/);assert.match(html,/5 clicks/);assert.match(html,/>12<\/strong>/);assert.match(html,/8 days left/);assert.match(html,/30 Oct 2026/);assert.match(html,/View stats/);assert.match(html,/Duplicate job/);assert.match(html,/View eligible candidates/);});

test('verified employer has no redundant company verification banner',()=>{const html=render('jobs',{...data,employer:{...data.employer,status:'verified'}});assert.doesNotMatch(html,/employer-verification|Company verified/);});
test('posted jobs paginate at twenty and expose management controls and state colors',()=>{const jobs=Array.from({length:45},(_,i)=>({id:i+1,title:`Role ${i+1}`,status:i%2?'paused':'active',openings:1,views_count:0}));const html=render('jobs',{...data,jobs});assert.equal((html.match(/<article class="posted-job-card/g)||[]).length,20);assert.match(html,/Page 1 of 3/);assert.match(html,/Filters/);assert.doesNotMatch(html,/Minimum offered salary|employer-job-filters/);assert.match(html,/Sort by/);assert.match(html,/job-state-paused/);assert.match(html,/Pause job/);assert.match(html,/Resume job/);});

test('expired job deadline does not claim to accept applications',()=>{const html=render('jobs',{...data,jobs:[{id:1,title:'Operator',status:'active',openings:1,applications_count:0,days_remaining:0,application_deadline:'2026-09-01'}]});assert.match(html,/Application deadline has passed/);assert.doesNotMatch(html,/Accepting applications/);});
