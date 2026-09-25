import React,{createContext,useContext,useState}from'react';
import{Routes,Route,Navigate}from'react-router-dom';
import Landing from'./pages/public/Landing.jsx';
import Login from'./pages/auth/Login.jsx';
import CandidateRegistration from'./pages/candidate/CandidateRegistration.jsx';
import CandidateProfile from'./pages/candidate/CandidateProfile.jsx';
import ResumeBuilder from'./pages/candidate/ResumeBuilder.jsx';
import AdminLayout from'./layouts/AdminLayout.jsx';
import Dashboard from'./pages/admin/Dashboard.jsx';
import Candidates from'./pages/admin/Candidates.jsx';
import CandidateDetail from'./pages/admin/CandidateDetail.jsx';
import Organizations from'./pages/admin/Organizations.jsx';
import Jobs from'./pages/admin/Jobs.jsx';
import JobForm from'./pages/admin/JobForm.jsx';
import Operations from'./pages/admin/Operations.jsx';
import Reports from'./pages/admin/Reports.jsx';
import Settings from'./pages/admin/Settings.jsx';
import Batches from'./pages/admin/Batches.jsx';
import RoleLayout from'./layouts/RoleLayout.jsx';
import EmployerDashboard from'./pages/employer/EmployerDashboard.jsx';
import CenterDashboard from'./pages/center/CenterDashboard.jsx';
import TrainingBatches from'./pages/center/TrainingBatches.jsx';
import CenterCandidates from'./pages/center/CenterCandidates.jsx';
import CenterCandidateRegistration from'./pages/center/CenterCandidateRegistration.jsx';
import{TrainingPartnerProfile,CenterProfile,AvailabilityPipeline}from'./pages/center/CenterSetupPages.jsx';
import CandidateDashboard from'./pages/candidate/CandidateDashboard.jsx';
import RolePage from'./pages/roles/RolePage.jsx';
import Candidate2Layout from'./layouts/Candidate2Layout.jsx';
import Candidate2Interests from'./pages/candidate/Candidate2Interests.jsx';
import Candidate2Opportunities from'./pages/candidate/Candidate2Opportunities.jsx';
import'./center-workspace.css';

const ToastCtx=createContext(()=>{});
export const useToast=()=>useContext(ToastCtx);
function Toasts({children}){const[msg,setMsg]=useState('');const show=m=>{setMsg(m);setTimeout(()=>setMsg(''),2600)};return <ToastCtx.Provider value={show}>{children}{msg&&<div className="toast">✓ {msg}</div>}</ToastCtx.Provider>}
const roleRoutes={employer:['onboarding','company','jobs','jobs/new','talent','interviews','joining','history','settings'],center:['jobs','interviews','placements','performance','settings'],candidate:['consent','opportunities','interests','interviews','joining','retention','privacy']};

export default function App(){return <Toasts><Routes>
  <Route path="/" element={<Landing/>}/><Route path="/login" element={<Login/>}/><Route path="/register/candidate" element={<CandidateRegistration mode="public"/>}/>
  <Route path="/admin" element={<AdminLayout/>}><Route index element={<Navigate to="dashboard" replace/>}/><Route path="dashboard" element={<Dashboard/>}/><Route path="candidates" element={<Candidates/>}/><Route path="candidates/new" element={<CandidateRegistration mode="admin"/>}/><Route path="candidates/:id" element={<CandidateDetail/>}/><Route path="centers" element={<Organizations type="center"/>}/><Route path="batches" element={<Batches/>}/><Route path="batches/:id" element={<Batches/>}/><Route path="employers" element={<Organizations type="employer"/>}/><Route path="jobs" element={<Jobs/>}/><Route path="jobs/new" element={<JobForm/>}/><Route path="jobs/:id/edit" element={<JobForm/>}/><Route path="matching" element={<Operations type="matching"/>}/><Route path="crm" element={<Operations type="crm"/>}/><Route path="interviews" element={<Operations type="interviews"/>}/><Route path="joining" element={<Operations type="joining"/>}/><Route path="retention" element={<Operations type="retention"/>}/><Route path="reports" element={<Reports/>}/><Route path="settings" element={<Settings/>}/></Route>
  <Route path="/employer" element={<RoleLayout role="employer"/>}><Route index element={<Navigate to="dashboard" replace/>}/><Route path="dashboard" element={<EmployerDashboard/>}/>{roleRoutes.employer.map(p=><Route key={p} path={p} element={<RolePage role="employer" page={p}/>}/>)}</Route>
  <Route path="/center" element={<RoleLayout role="center"/>}><Route index element={<Navigate to="dashboard" replace/>}/><Route path="dashboard" element={<CenterDashboard/>}/><Route path="partner" element={<TrainingPartnerProfile/>}/><Route path="profile" element={<CenterProfile/>}/><Route path="pipeline" element={<AvailabilityPipeline/>}/><Route path="batches" element={<TrainingBatches/>}/><Route path="candidates" element={<CenterCandidates/>}/><Route path="candidates/new" element={<CenterCandidateRegistration/>}/>{roleRoutes.center.map(p=><Route key={p} path={p} element={<RolePage role="center" page={p}/>}/>)}</Route>
  <Route path="/candidate" element={<RoleLayout role="candidate"/>}><Route index element={<Navigate to="dashboard" replace/>}/><Route path="dashboard" element={<CandidateDashboard/>}/><Route path="profile" element={<CandidateProfile/>}/><Route path="resume" element={<ResumeBuilder/>}/>{roleRoutes.candidate.map(p=><Route key={p} path={p} element={<RolePage role="candidate" page={p}/>}/>)}</Route>
  <Route path="/candidate2" element={<Candidate2Layout/>}><Route index element={<Navigate to="interests" replace/>}/><Route path="dashboard" element={<Navigate to="/candidate2/interests" replace/>}/><Route path="profile" element={<CandidateProfile/>}/><Route path="resume" element={<ResumeBuilder/>}/>{roleRoutes.candidate.map(p=><Route key={p} path={p} element={p==='interests'?<Candidate2Interests/>:p==='opportunities'?<Candidate2Opportunities/>:<RolePage role="candidate" page={p}/>}/>)}</Route>
  <Route path="*" element={<Navigate to="/"/>}/>
</Routes></Toasts>}
