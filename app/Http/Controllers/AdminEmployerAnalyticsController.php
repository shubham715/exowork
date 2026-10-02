<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class AdminEmployerAnalyticsController extends Controller
{
    private function authorize(Request $request, bool $manage = false): void
    {
        abort_unless($request->user()?->hasPermission('employers.manage') || (!$manage && $request->user()?->hasPermission('employers.view')), 403);
    }

    private function listQuery(Request $request)
    {
        $filters=$request->validate([
            'search'=>['nullable','string','max:255'],
            'statuses'=>['nullable','array','max:4'],'statuses.*'=>[Rule::in(['pending','verified','rejected','inactive'])],
            'industries'=>['nullable','array','max:100'],'industries.*'=>['string','max:80'],
            'states'=>['nullable','array','max:100'],'states.*'=>['string','max:80'],
            'access'=>['nullable','array','max:2'],'access.*'=>[Rule::in(['active','inactive'])],
            'from'=>['nullable','date_format:Y-m-d'],'to'=>['nullable','date_format:Y-m-d','after_or_equal:from'],
            'sort'=>['nullable',Rule::in(['legal_name','industry','contact_name','status','jobs_count','hires_count','created_at'])],
            'direction'=>['nullable',Rule::in(['asc','desc'])],
            'page'=>['nullable','integer','min:1'],'per_page'=>['nullable','integer',Rule::in([25,50,100])],
        ]);
        $query=DB::table('employers')->whereNull('employers.deleted_at');
        foreach(['statuses'=>'status','industries'=>'industry','states'=>'state'] as $key=>$column) if(!empty($filters[$key])) $query->whereIn('employers.'.$column,$filters[$key]);
        if(count($filters['access']??[])===1) $query->where('employers.status', $filters['access'][0]==='inactive'?'=':'!=', 'inactive');
        if(!empty($filters['from'])) $query->where('employers.created_at','>=',$filters['from'].' 00:00:00');
        if(!empty($filters['to'])) $query->where('employers.created_at','<',Carbon::parse($filters['to'])->addDay()->startOfDay());
        if(!empty(trim($filters['search']??''))) {
            $search='%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower(trim($filters['search']))).'%';
            $query->where(function($q) use($search){foreach(['name','legal_name','code','email','phone','contact_name','contact_phone','district','industry'] as $column) $q->orWhereRaw("LOWER(employers.$column) LIKE ? ESCAPE '!'",[$search]);});
        }
        return $query->select('employers.*')
            ->selectSub(DB::table('job_posts')->selectRaw('COUNT(*)')->whereColumn('employer_id','employers.id')->whereNull('deleted_at')->whereNotNull('published_at'),'jobs_count')
            ->selectSub(DB::table('placement_outcomes as p')->join('applications as a','a.id','=','p.application_id')->join('job_posts as j','j.id','=','a.job_post_id')->join('candidates as c','c.id','=','a.candidate_id')->selectRaw('COUNT(DISTINCT a.candidate_id)')->whereColumn('j.employer_id','employers.id')->whereNull('j.deleted_at')->whereNull('c.deleted_at')->whereNotNull('p.joined_on'),'hires_count')
            ->orderBy($filters['sort']??'created_at',$filters['direction']??'desc')->orderByDesc('employers.id');
    }

    private function listProfile(object $employer): array
    {
        $row=(array)$employer;unset($row['document_path']);
        $row['document_type']=strtolower(pathinfo($employer->document_path??$employer->document_name??'',PATHINFO_EXTENSION));
        $row['document_url']=$employer->document_path?"/admin-api/employers/{$employer->id}/document":null;
        return $row;
    }

    public function index(Request $request)
    {
        $this->authorize($request);
        $page=$this->listQuery($request)->paginate((int)$request->input('per_page',25));
        $base=DB::table('employers')->whereNull('deleted_at');
        return response()->json([
            'employers'=>$page->getCollection()->map(fn($e)=>$this->listProfile($e)),
            'can_manage'=>$request->user()->hasPermission('employers.manage'),
            'pagination'=>['total'=>$page->total(),'current_page'=>$page->currentPage(),'last_page'=>$page->lastPage(),'per_page'=>$page->perPage(),'from'=>$page->firstItem(),'to'=>$page->lastItem()],
            'counts'=>[...(clone $base)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total','status')->all(),'all'=>(clone $base)->count()],
            'filters'=>['industries'=>(clone $base)->whereNotNull('industry')->where('industry','!=','')->distinct()->orderBy('industry')->pluck('industry'),'states'=>(clone $base)->whereNotNull('state')->where('state','!=','')->distinct()->orderBy('state')->pluck('state')],
        ]);
    }

    public function show(Request $request,int $id)
    {
        $this->authorize($request);
        $employer=$this->listQuery(new Request())->where('employers.id',$id)->first();
        abort_unless($employer,404);
        return response()->json(['employer'=>$this->listProfile($employer)]);
    }

    public function export(Request $request)
    {
        $this->authorize($request);
        $query=$this->listQuery($request);
        return response()->streamDownload(function() use($query) {
            $file=fopen('php://output','w');fwrite($file,"\xEF\xBB\xBF");
            fputcsv($file,['Employer','Code','Industry','State','District','Contact','Email','Phone','Status','Published jobs','Joined candidates','Registered']);
            foreach($query->lazy(500) as $e) {
                $values=[$e->legal_name?:$e->name,$e->code,$e->industry,$e->state,$e->district,$e->contact_name,$e->email,$e->contact_phone?:$e->phone,$e->status,$e->jobs_count,$e->hires_count,$e->created_at];
                fputcsv($file,array_map(fn($value)=>preg_match('/^[=+@\-\t\r]/',(string)$value)?"'".$value:$value,$values));
            }
            fclose($file);
        },'exowork-employers.csv',['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'no-store, private']);
    }

    public function update(Request $request, int $id)
    {
        $this->authorize($request, true);
        $employer = DB::table('employers')->whereNull('deleted_at')->find($id);
        abort_unless($employer, 404);
        abort_if($employer->status === 'inactive', 422, 'Inactive employers must be reactivated before editing.');
        $data = $request->validate([
            'name' => ['required','string','max:100'], 'legal_name' => ['required','string','max:255'],
            'industry' => ['nullable','string','max:80'], 'website' => ['nullable','url','max:255'],
            'description' => ['nullable','string','max:5000'],
            'gstin' => ['nullable','regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/',Rule::unique('employers','gstin')->ignore($id)],
            'state' => ['nullable','string','max:80'], 'district' => ['nullable','string','max:80'],
            'address' => ['nullable','string','max:1000'], 'pincode' => ['nullable','digits:6'],
            'contact_name' => ['nullable','string','max:255'], 'contact_designation' => ['nullable','string','max:100'],
            'contact_department' => ['nullable','string','max:100'], 'contact_phone' => ['nullable','string','max:30'],
        ]);
        $changed = collect($data)->contains(fn($value, $key) => (string)$value !== (string)$employer->$key);
        if ($changed) DB::transaction(function () use ($request, $employer, $data, $id) {
            DB::table('employers')->where('id',$id)->update([...$data,'status'=>'pending','verified_at'=>null,'verified_by_user_id'=>null,'review_remarks'=>null,'updated_at'=>now()]);
            DB::table('job_posts')->where('employer_id',$id)->whereIn('status',['active','published'])->update(['status'=>'draft','updated_at'=>now()]);
            DB::table('audit_logs')->insert(['actor_user_id'=>$request->user()->id,'action'=>'admin.employer_updated','subject_type'=>'employer','subject_id'=>$id,'changes'=>json_encode(['status'=>'pending','fields'=>array_keys($data)]),'ip_address'=>$request->ip(),'created_at'=>now()]);
        });
        return app(EmployerWorkspaceController::class)->adminIndex($request);
    }

    public function bulk(Request $request)
    {
        $this->authorize($request, true);
        $data=$request->validate(['ids'=>['required','array','min:1','max:100'],'ids.*'=>['required','integer','distinct'], 'action'=>['required',Rule::in(['approve','reject','activate','disable'])], 'remarks'=>[$request->input('action')==='reject'?'required':'nullable','string','max:255']]);
        DB::transaction(function() use ($request,$data) {
            $rows=DB::table('employers')->whereIn('id',$data['ids'])->whereNull('deleted_at')->lockForUpdate()->get();
            abort_unless($rows->count()===count($data['ids']),422,'Some selected profiles no longer exist. Refresh and try again.');
            foreach($rows as $e) {
                if(in_array($data['action'],['approve','reject'])) abort_if($e->status==='inactive',422,'Activate inactive profiles before reviewing them.');
                if($data['action']==='activate') abort_unless($e->status==='inactive',422,'Select only inactive profiles to activate.');

            }
            $status=match($data['action']) {'approve'=>'verified','reject'=>'rejected','activate'=>'pending','disable'=>'inactive'};
            foreach($rows as $e) {
                DB::table('employers')->where('id',$e->id)->update(['status'=>$status,'verified_at'=>$status==='verified'?now():null,'verified_by_user_id'=>$status==='verified'?$request->user()->id:null,'review_remarks'=>$data['remarks']??null,'updated_at'=>now()]);
                if($status!=='verified') DB::table('job_posts')->where('employer_id',$e->id)->whereIn('status',['active','published'])->update(['status'=>'draft','updated_at'=>now()]);
                DB::table('audit_logs')->insert(['actor_user_id'=>$request->user()->id,'action'=>'admin.employer_'.$data['action'],'subject_type'=>'employer','subject_id'=>$e->id,'changes'=>json_encode(['previous_status'=>$e->status,'status'=>$status,'remarks'=>$data['remarks']??null,'approval_basis'=>$data['action']==='approve'?'admin_decision':null,'missing_profile_fields'=>$data['action']==='approve'?array_values(array_filter(['legal_name','gstin','address','pincode','document_path'],fn($key)=>!$e->$key)):[]]),'ip_address'=>$request->ip(),'created_at'=>now()]);
                if(in_array($data['action'],['approve','reject'])) DB::table('audit_logs')->insert(['actor_user_id'=>$request->user()->id,'action'=>'employer.reviewed','subject_type'=>'employer','subject_id'=>$e->id,'changes'=>json_encode(['status'=>$status,'review_remarks'=>$data['remarks']??null,'approval_basis'=>$data['action']==='approve'?'admin_decision':null]),'ip_address'=>$request->ip(),'created_at'=>now()]);
            }
        });
        return app(EmployerWorkspaceController::class)->adminIndex($request);
    }

    public function stats(Request $request)
    {
        $this->authorize($request);
        $filters = $request->validate([
            'from'=>['nullable','date_format:Y-m'], 'to'=>['nullable','date_format:Y-m','after_or_equal:from'],
            'industry'=>['nullable','string','max:80'], 'state'=>['nullable','string','max:80'],
            'status'=>['nullable',Rule::in(['pending','verified','rejected','inactive'])],
        ]);
        $from = Carbon::createFromFormat('!Y-m', $filters['from'] ?? now()->subMonths(5)->format('Y-m'))->startOfMonth();
        $to = Carbon::createFromFormat('!Y-m', $filters['to'] ?? now()->format('Y-m'))->endOfMonth();
        abort_if($from->gt($to) || $from->diffInMonths($to) > 59, 422, 'Choose an ordered range of up to 60 months.');
        $employers = DB::table('employers')->whereNull('deleted_at')->where(fn($q)=>$q->whereNull('created_at')->orWhere('created_at','<=',$to));
        foreach (['industry','state','status'] as $key) if (!empty($filters[$key])) $employers->where($key,$filters[$key]);
        $employers = $employers->get();
        $ids = $employers->pluck('id');
        $jobs = DB::table('job_posts')->whereIn('employer_id',$ids)->whereNull('deleted_at')->whereBetween('published_at',[$from,$to])->get(['id','employer_id','published_at','openings']);
        $hires = DB::table('placement_outcomes as p')->join('applications as a','a.id','=','p.application_id')->join('job_posts as j','j.id','=','a.job_post_id')->join('candidates as c','c.id','=','a.candidate_id')->whereIn('j.employer_id',$ids)->whereNull('j.deleted_at')->whereNull('c.deleted_at')->whereBetween('p.joined_on',[$from->toDateString(),$to->toDateString()])->select('j.employer_id','a.candidate_id','p.joined_on')->get();
        // One candidate per employer is one hire, even if duplicate outcome records exist.
        $hires = $hires->sortBy('joined_on')->unique(fn($row)=>$row->employer_id.':'.$row->candidate_id)->values();
        $months = [];
        for ($month=$from->copy(); $month->lte($to); $month->addMonth()) {
            $key=$month->format('Y-m');
            $months[]=['month'=>$key,'registrations'=>$employers->filter(fn($e)=>substr((string)$e->created_at,0,7)===$key)->count(),'jobs'=>$jobs->filter(fn($j)=>substr($j->published_at,0,7)===$key)->count(),'hires'=>$hires->filter(fn($h)=>substr($h->joined_on,0,7)===$key)->count()];
        }
        $performance=$employers->map(fn($e)=>['id'=>$e->id,'name'=>$e->legal_name ?: $e->name,'code'=>$e->code,'industry'=>$e->industry,'state'=>$e->state,'status'=>$e->status,'jobs'=>$jobs->where('employer_id',$e->id)->count(),'openings'=>$jobs->where('employer_id',$e->id)->sum('openings'),'hires'=>$hires->where('employer_id',$e->id)->count()]);
        return response()->json(['summary'=>['employers'=>$employers->count(),'new_employers'=>$employers->whereBetween('created_at',[$from->toDateTimeString(),$to->toDateTimeString()])->count(),'jobs'=>$jobs->count(),'hires'=>$hires->count(),'hiring_employers'=>$hires->pluck('employer_id')->unique()->count()],'months'=>$months,'performance'=>$performance,'statuses'=>$employers->countBy('status'),'industries'=>$employers->countBy(fn($e)=>$e->industry ?: 'Unspecified'),'from'=>$from->format('Y-m'),'to'=>$to->format('Y-m')]);
    }
}
