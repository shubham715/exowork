<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Hash, Storage};
use Illuminate\Validation\{Rule, ValidationException};
use Illuminate\Support\Str;

class EmployerWorkspaceController extends Controller
{
    private function profileRules(): array {
        return [
            'name' => ['required','string','max:100'], 'legal_name' => ['required','string','max:255'],
            'industry' => ['required','string','max:80'], 'website' => ['nullable','url','max:255'],
            'description' => ['required','string','max:5000'],
            'gstin' => ['nullable','regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'state' => ['required','string','max:80'], 'district' => ['required','string','max:80'],
            'address' => ['required','string','max:1000'], 'pincode' => ['required','digits:6'],
            'contact_name' => ['nullable','string','max:255'], 'contact_designation' => ['nullable','string','max:100'],
            'contact_phone' => ['nullable','string','max:30'], 'contact_department' => ['nullable','string','max:100'],
            'document' => ['nullable','file','mimes:pdf,jpg,jpeg,png','max:5120'],
        ];
    }
    public function register(Request $request) {
        $request->merge(['email' => strtolower(trim((string)$request->input('email'))), 'gstin' => ($request->filled('gstin') ? strtoupper(trim((string)$request->input('gstin'))) : null)]);
        $data = $request->validate([...$this->profileRules(),
            'industry'=>['nullable','string','max:80'], 'description'=>['nullable','string','max:5000'],
            'state'=>['nullable','string','max:80'], 'district'=>['nullable','string','max:80'],
            'address'=>['nullable','string','max:1000'], 'pincode'=>['nullable','digits:6'],
            'gstin' => [...$this->profileRules()['gstin'], Rule::unique('employers','gstin')],
            'account_name' => ['required','string','max:255'],
            'email' => ['required','email','max:255','unique:users,email'],
            'phone' => ['nullable','regex:/^[0-9]{10}$/','unique:users,phone'],
            'password' => ['required','string','min:8','confirmed'],
            'terms_accepted' => ['accepted'], 'privacy_accepted' => ['accepted'],
            'document' => ['nullable','file','mimes:pdf,jpg,jpeg,png','max:5120'],
            'website_check' => ['nullable','max:0'],
        ]);
        $path = $request->hasFile('document') ? $request->file('document')->store('employer-documents', 'local') : null;
        if ($request->hasFile('document')) abort_unless($path, 500, 'Document could not be stored.');
        try {
            $userId = DB::transaction(function () use ($request,$data,$path) {
                $userId = DB::table('users')->insertGetId(['name'=>($data['account_name'] ?? $data['contact_name'] ?? $data['name']),'email'=>$data['email'],'phone'=>($data['phone'] ?? null),'password'=>Hash::make($data['password']),'status'=>'active','created_at'=>now(),'updated_at'=>now()]);
                $profile = array_intersect_key($data, $this->profileRules()); unset($profile['document']);
                $profile['contact_name'] = $data['contact_name'] ?? $data['account_name'];
                $profile['contact_phone'] = $data['contact_phone'] ?? $data['phone'] ?? null;
                $employerId = DB::table('employers')->insertGetId([...$profile,'email'=>$data['email'],'phone'=>($data['phone'] ?? null),'code'=>'EXO-EMP-'.Str::upper(Str::random(10)),'status'=>'pending','document_path'=>$path,'document_name'=>$request->file('document')?->getClientOriginalName(),'terms_accepted_at'=>now(),'privacy_accepted_at'=>now(),'notice_version'=>'employer-v1','created_at'=>now(),'updated_at'=>now()]);
                $role = DB::table('roles')->where('key','employer')->value('id');
                if (!$role) $role = DB::table('roles')->insertGetId(['key'=>'employer','name'=>'Employer','created_at'=>now(),'updated_at'=>now()]);
                DB::table('user_roles')->insert(['user_id'=>$userId,'role_id'=>$role]);
                DB::table('organization_memberships')->insert(['user_id'=>$userId,'employer_id'=>$employerId,'membership_role'=>'owner','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
                $this->audit($request,'employer.registered','employer',$employerId,['status'=>'pending'],$userId);
                return $userId;
            });
        } catch (\Throwable $e) { if ($path) Storage::disk('local')->delete($path); throw $e; }
        Auth::guard('candidate')->logout(); Auth::guard('web')->loginUsingId($userId); $request->session()->regenerate();
        return response()->json(['destination'=>'/employer/dashboard','csrf_token'=>csrf_token()],201);
    }
    public function employer(Request $request): object {
        abort_unless($request->user() && DB::table('user_roles')->join('roles','roles.id','=','user_roles.role_id')->where('user_roles.user_id',$request->user()->id)->where('roles.key','employer')->exists(),403);
        $row=DB::table('organization_memberships as m')->join('employers as e','e.id','=','m.employer_id')->where('m.user_id',$request->user()->id)->where('m.status','active')->whereNull('e.deleted_at')->select('e.*')->first();
        abort_unless($row,403,'No active employer membership.'); abort_if($row->status==='inactive',403,'Employer account is inactive.'); return $row;
    }
    private function publicProfile(object $e): array {
        $result=(array)$e; unset($result['document_path']);
        $result['document_url']=$e->document_path?'/employer-api/document':null;
        $result['initials']=Str::upper(collect(explode(' ',$e->name))->take(2)->map(fn($word)=>Str::substr($word,0,1))->implode(''));
        return $result;
    }
    public function identity(Request $request) { return response()->json(['employer'=>$this->publicProfile($this->employer($request))]); }
    public function workspace(Request $request) {
        $e=$this->employer($request);
        $jobs=DB::table('job_posts')->where('employer_id',$e->id)->whereNull('deleted_at')
            ->select('job_posts.*')
            ->selectSub(DB::table('applications')->selectRaw('COUNT(*)')->whereColumn('applications.job_post_id','job_posts.id'), 'applications_count')
            ->orderByDesc('id')->get();
        foreach ($jobs as $job) {
            $job->views_count = null; // Candidate opportunity views are not collected yet.
            $job->days_remaining = $job->application_deadline
                ? max(0, (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($job->application_deadline)->startOfDay(), false))
                : null;
        }
        // Identity is released only once operations schedules an interview for an application.
        $interviews=DB::table('interviews as i')->join('applications as a','a.id','=','i.application_id')->join('job_posts as j','j.id','=','a.job_post_id')->join('candidates as c','c.id','=','a.candidate_id')->where('j.employer_id',$e->id)->whereNull('j.deleted_at')->whereNull('c.deleted_at')->select('i.id','i.scheduled_at','i.mode','i.location','i.status','j.title','j.id as job_post_id','a.id as application_id','c.id as candidate_id','c.first_name','c.last_name')->orderBy('i.scheduled_at')->get();
        $placements=DB::table('placement_outcomes as p')->join('applications as a','a.id','=','p.application_id')->join('job_posts as j','j.id','=','a.job_post_id')->join('candidates as c','c.id','=','a.candidate_id')->where('j.employer_id',$e->id)->whereNull('j.deleted_at')->whereNull('c.deleted_at')->whereExists(fn($q)=>$q->selectRaw('1')->from('interviews as i')->whereColumn('i.application_id','a.id'))->select('p.id','p.status','p.expected_joining_on','p.joined_on','p.monthly_salary','j.id as job_post_id','j.title','c.first_name','c.last_name')->get();
        return response()->json(['employer'=>$this->publicProfile($e),'jobs'=>$jobs,'interviews'=>$interviews,'placements'=>$placements,'summary'=>['active_jobs'=>$jobs->whereIn('status',['active','published'])->count(),'draft_jobs'=>$jobs->where('status','draft')->count(),'candidates'=>$interviews->pluck('candidate_id')->unique()->count(),'interviews'=>$interviews->whereIn('status',['scheduled','confirmed'])->count(),'joined'=>$placements->whereNotNull('joined_on')->count()]]);
    }
    public function updateProfile(Request $request) {
        $e=$this->employer($request); $request->merge(['gstin'=>($request->filled('gstin') ? strtoupper(trim((string)$request->input('gstin'))) : null)]);
        $data=$request->validate([...$this->profileRules(),'gstin'=>[...$this->profileRules()['gstin'],Rule::unique('employers','gstin')->ignore($e->id)]]); unset($data['document']);
        $changed=false; foreach($data as $key=>$value) if((string)$value !== (string)$e->$key) $changed=true;
        if($request->hasFile('document')) { $data['document_path']=$request->file('document')->store('employer-documents','local'); abort_unless($data['document_path'],500); $data['document_name']=$request->file('document')->getClientOriginalName(); $changed=true; }
        if($changed) $data=[...$data,'status'=>'pending','verified_at'=>null,'verified_by_user_id'=>null,'review_remarks'=>null];
        try {
            DB::transaction(function() use($request,$e,$data){
                DB::table('employers')->where('id',$e->id)->update([...$data,'updated_at'=>now()]);
                if(isset($data['status'])) DB::table('job_posts')->where('employer_id',$e->id)->whereIn('status',['active','published'])->update(['status'=>'draft','updated_at'=>now()]);
                $this->audit($request,'employer.profile_updated','employer',$e->id,['status'=>$data['status']??$e->status]);
            });
        } catch (\Throwable $error) {
            if(isset($data['document_path'])) Storage::disk('local')->delete($data['document_path']);
            throw $error;
        }

        return $this->identity($request);
    }
    public function document(Request $request) { $e=$this->employer($request); return $this->download($e); }
    private function download(object $e) { abort_unless($e->document_path && Storage::disk('local')->exists($e->document_path),404); return Storage::disk('local')->download($e->document_path,$e->document_name); }
    public function saveJob(Request $request, ?int $id=null) {
        $e=$this->employer($request);
        $old=$id?DB::table('job_posts')->where('employer_id',$e->id)->whereNull('deleted_at')->find($id):null; if($id)abort_unless($old,404);
        $publish=$request->input('status')==='active'; $required=$publish?'required':'nullable';
        $data=$request->validate([
            'title'=>['required','string','max:255'],'status'=>['required',Rule::in(['draft','active','filled','closed'])],
            'description'=>[$required,'string','max:10000'],'industry'=>[$required,'string','max:80'],
            'state'=>[$required,'string','max:80'],'district'=>[$required,'string','max:80'],'location'=>[$required,'string','max:255'],
            'openings'=>['required','integer','min:1','max:100000'],
            'salary_min'=>['nullable','integer','min:0'],'salary_max'=>['nullable','integer','min:0','gte:salary_min'],
            'department'=>[$required,'string','max:100'],'job_type'=>[$required,Rule::in(['Full-Time','Part-Time','Contract','Internship','Freelance'])],
            'workplace_type'=>[$required,Rule::in(['On-site','Remote','Hybrid'])],
            'education'=>[$required,'string','max:255'],'experience'=>[$required,'string','max:255'],
            'salary_type'=>[$required,Rule::in(['Fixed','Hourly','Incentive-based','Negotiable'])],
            'responsibilities'=>[$required,'string','max:5000'],'skills'=>[$required,'string','max:3000'],
            'benefits'=>['nullable','string','max:3000'],'screening_questions'=>['nullable','string','max:3000'],
            'application_deadline'=>[$required,'date',...($publish?['after_or_equal:today']:[])],
        ]);
        if($publish && $e->status!=='verified') throw ValidationException::withMessages(['status'=>'Your company must be verified before publishing jobs.']);
        if(!$id && in_array($data['status'],['closed','filled'])) throw ValidationException::withMessages(['status'=>'Create a draft or publish a job first.']);
        DB::transaction(function() use($request,$e,$data,$old,$id){
            $row=[...$data,'updated_at'=>now()];
            if($data['status']==='active')$row['published_at']=$old?->published_at??now();
            if($id) DB::table('job_posts')->where('id',$id)->update($row);
            else $id=DB::table('job_posts')->insertGetId([...$row,'employer_id'=>$e->id,'created_by_user_id'=>$request->user()->id,'created_at'=>now()]);
            $this->audit($request,'employer.job_saved','job_post',$id,['status'=>$data['status']]);
        });
        return $this->workspace($request);
    }
    private function admin(Request $request,bool $manage=false): void { abort_unless($request->user()?->hasPermission('employers.manage') || (!$manage && $request->user()?->hasPermission('employers.view')),403); }
    public function adminIndex(Request $request) {
        return app(AdminEmployerAnalyticsController::class)->index($request);
    }
    public function adminDocument(Request $request,int $id) {
        $this->admin($request);$e=DB::table('employers')->whereNull('deleted_at')->find($id);abort_unless($e,404);
        if(!$request->boolean('preview')) return $this->download($e);
        abort_unless($e->document_path && Storage::disk('local')->exists($e->document_path),404);
        $mime=Storage::disk('local')->mimeType($e->document_path);
        if(!in_array($mime,['image/jpeg','image/png','application/pdf'],true)) return $this->download($e);
        return response()->file(Storage::disk('local')->path($e->document_path),['Content-Type'=>$mime,'X-Content-Type-Options'=>'nosniff','Cache-Control'=>'no-store, private'])->setContentDisposition('inline','verification-document.'.match($mime){'image/jpeg'=>'jpg','image/png'=>'png',default=>'pdf'});
    }
    public function review(Request $request,int $id) {
        $this->admin($request,true);
        abort_unless(DB::table('employers')->whereNull('deleted_at')->where('id',$id)->exists(),404);
        $data=$request->validate(['status'=>['required',Rule::in(['verified','rejected'])],'review_remarks'=>[$request->input('status')==='rejected'?'required':'nullable','string','max:255']]);
        $request->merge(['ids'=>[$id],'action'=>$data['status']==='verified'?'approve':'reject','remarks'=>$data['review_remarks']??null]);
        return app(AdminEmployerAnalyticsController::class)->bulk($request);
    }
    private function audit(Request $request,string $action,string $type,int $id,array $changes,?int $actor=null): void { DB::table('audit_logs')->insert(['actor_user_id'=>$actor??$request->user()?->id,'action'=>$action,'subject_type'=>$type,'subject_id'=>$id,'changes'=>json_encode($changes),'ip_address'=>$request->ip(),'created_at'=>now()]); }
}

