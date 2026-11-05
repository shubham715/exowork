<?php
namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\User;
use App\Services\AccountImpersonation;
use Database\Seeders\PlatformRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->seed(PlatformRolesSeeder::class); }
    private function user(string $role): User {
        $user=User::factory()->create(['status'=>'active']);
        DB::table('user_roles')->insert(['user_id'=>$user->id,'role_id'=>DB::table('roles')->where('key',$role)->value('id')]);
        return $user;
    }
    private function candidate(): Candidate {
        return Candidate::forceCreate(['profile_status'=>'active','first_name'=>'Asha','last_name'=>'Rao','candidate_code'=>'EXO-SUPPORT','whatsapp'=>'9876543222','gender'=>'Female','age'=>24,'qualification'=>'ITI','state'=>'Maharashtra','district'=>'Pune','current_location'=>'Pune','permanent_location'=>'Pune','preferred_locations'=>['Pune'],'relocation_preference'=>'No','skills'=>'Machine operation','experience_type'=>'Fresher','expected_monthly_salary'=>15000,'availability'=>'Yes','preferred_industry'=>'Manufacturing','languages'=>['Hindi'],'training_status'=>'Not enrolled']);
    }
    private function login(User $admin): void {
        $this->actingAs($admin,'web')->withSession(['admin.authenticated_at'=>now()->timestamp]);
    }
    private function organization(User $user, string $type): int {
        $table=$type==='employer'?'employers':'training_centers';
        $id=DB::table($table)->insertGetId(['code'=>uniqid('SUPPORT'),'name'=>'Support Organization','status'=>'pending',...($type==='center'?['state'=>'Maharashtra','district'=>'Pune']:[])]);
        DB::table('organization_memberships')->insert(['user_id'=>$user->id,($type==='employer'?'employer_id':'training_center_id')=>$id,'status'=>'active','membership_role'=>'owner']);
        return $id;
    }
    public function test_candidate_support_session_is_view_only_and_restores_original_admin(): void {
        $admin=$this->user('admin');$candidate=$this->candidate();$this->login($admin);
        $this->getJson('/auth/impersonation')->assertJsonPath('can_impersonate',true);
        $oldSession=session()->getId();
        $this->postJson('/auth/impersonation/start',['type'=>'candidate','id'=>$candidate->id])->assertOk()->assertJsonPath('destination','/candidate/dashboard');
        $this->assertNotSame($oldSession,session()->getId());$this->assertAuthenticatedAs($candidate,'candidate');$this->assertGuest('web');
        $this->getJson('/candidate-api/profile')->assertOk()->assertJsonPath('candidate.first_name','Asha');
        $this->getJson('/auth/impersonation')->assertJsonPath('active',true)->assertJsonPath('session.read_only',true)->assertJsonMissingPath('session.actor_id')->assertJsonMissingPath('session.actor_password');
        $this->getJson('/admin-api/candidates')->assertForbidden();
        $this->postJson('/auth/impersonation/start',['type'=>'candidate','id'=>$candidate->id])->assertForbidden();
        $this->patchJson('/candidate-api/profile',['first_name'=>'Changed'])->assertForbidden();
        $this->postJson('/auth/login',['role'=>'admin'])->assertForbidden();
        $this->postJson('/auth/employer/password/email',['email'=>'test@example.test'])->assertForbidden();
        $this->assertDatabaseHas('candidates',['id'=>$candidate->id,'first_name'=>'Asha']);
        $during=session()->getId();
        $this->postJson('/auth/impersonation/stop',['actor_id'=>9999])->assertOk()->assertJsonPath('destination','/admin/candidates');
        $this->assertNotSame($during,session()->getId());$this->assertAuthenticatedAs($admin,'web');$this->assertGuest('candidate');
        $this->assertFalse(session()->has(AccountImpersonation::KEY));
        $this->assertDatabaseHas('audit_logs',['actor_user_id'=>$admin->id,'action'=>'impersonation.started','subject_type'=>'candidate','subject_id'=>$candidate->id]);
        $this->assertDatabaseHas('audit_logs',['actor_user_id'=>$admin->id,'action'=>'impersonation.blocked']);
        $this->assertDatabaseHas('audit_logs',['actor_user_id'=>$admin->id,'action'=>'impersonation.ended']);
    }
    public function test_only_privileged_admins_with_recent_authentication_can_enter(): void {
        $candidate=$this->candidate();
        $this->postJson('/auth/impersonation/start',['type'=>'candidate','id'=>$candidate->id])->assertUnauthorized();
        foreach(['agent','employer','training_partner'] as $role){$user=$this->user($role);$this->login($user);$this->postJson('/auth/impersonation/start',['type'=>'candidate','id'=>$candidate->id])->assertForbidden();}
        $admin=$this->user('admin');$this->login($admin);
        $this->withSession(['admin.authenticated_at'=>now()->subHours(4)->timestamp])->postJson('/auth/impersonation/start',['type'=>'candidate','id'=>$candidate->id])->assertForbidden();
        $this->login($admin);DB::table('user_permissions')->insert(['user_id'=>$admin->id,'permission_id'=>DB::table('permissions')->where('key','users.manage')->value('id'),'allowed'=>false]);
        $this->postJson('/auth/impersonation/start',['type'=>'candidate','id'=>$candidate->id])->assertForbidden();
        $super=$this->user('superadmin');$this->login($super);
        $this->postJson('/auth/impersonation/start',['type'=>'candidate','id'=>$candidate->id])->assertOk();
        $this->postJson('/auth/impersonation/stop')->assertOk();$this->assertAuthenticatedAs($super,'web');
    }
    public function test_employer_and_training_partner_enter_correct_workspace_without_admin_access(): void {
        foreach(['employer','center'] as $type){
            $admin=$this->user('admin');$user=$this->user($type==='center'?'training_partner':'employer');$id=$this->organization($user,$type);$this->login($admin);
            $this->postJson('/auth/impersonation/start',['type'=>$type,'id'=>$id])->assertOk()->assertJsonPath('destination','/'.$type.'/dashboard');
            $this->assertAuthenticatedAs($user,'web');
            $this->getJson('/'.$type.'-api/identity')->assertOk();
            $this->getJson('/admin-api/review-notifications')->assertForbidden();
            $this->getJson('/'.($type==='employer'?'center':'employer').'-api/identity')->assertForbidden();
            $this->postJson('/auth/impersonation/stop')->assertOk();$this->assertAuthenticatedAs($admin,'web');
        }
    }
    public function test_inactive_privileged_and_ambiguous_targets_are_refused(): void {
        $admin=$this->user('admin');$this->login($admin);$candidate=$this->candidate();$candidate->update(['profile_status'=>'inactive']);
        $this->postJson('/auth/impersonation/start',['type'=>'candidate','id'=>$candidate->id])->assertUnprocessable();
        $this->postJson('/auth/impersonation/start',['type'=>'admin','id'=>$admin->id])->assertUnprocessable();
        $this->postJson('/auth/impersonation/start',['type'=>'candidate','id'=>9999])->assertNotFound();
        $owner=$this->user('employer');$id=$this->organization($owner,'employer');$this->organization($owner,'employer');
        $this->postJson('/auth/impersonation/start',['type'=>'employer','id'=>$id])->assertUnprocessable();
        DB::table('user_roles')->insert(['user_id'=>$owner->id,'role_id'=>DB::table('roles')->where('key','admin')->value('id')]);
        $this->postJson('/auth/impersonation/start',['type'=>'employer','id'=>$id])->assertForbidden();
        $this->assertAuthenticatedAs($admin,'web');
    }
    public function test_expiry_and_revocation_never_allow_target_to_gain_admin_privileges(): void {
        $admin=$this->user('admin');$candidate=$this->candidate();$this->login($admin);
        $this->postJson('/auth/impersonation/start',['type'=>'candidate','id'=>$candidate->id])->assertOk();
        $this->travel(31)->minutes();
        $this->getJson('/auth/impersonation')->assertJsonPath('session.expired',true);
        $this->getJson('/candidate-api/profile')->assertStatus(409)->assertJsonPath('support_destination','/admin/candidates');
        $this->assertAuthenticatedAs($admin,'web');
        $this->login($admin);$this->postJson('/auth/impersonation/start',['type'=>'candidate','id'=>$candidate->id])->assertOk();
        $admin->forceFill(['status'=>'inactive'])->save();
        $this->postJson('/auth/impersonation/stop')->assertUnauthorized();$this->assertGuest('web');$this->assertGuest('candidate');
        $this->assertFalse(session()->has(AccountImpersonation::KEY));
        $this->postJson('/auth/impersonation/stop')->assertStatus(409);
    }
    public function test_normal_logout_discards_restore_capability(): void {
        $admin=$this->user('admin');$candidate=$this->candidate();$this->login($admin);
        $this->postJson('/auth/impersonation/start',['type'=>'candidate','id'=>$candidate->id])->assertOk();
        $this->postJson('/auth/logout')->assertOk();$this->assertGuest('web');$this->assertGuest('candidate');
        $this->postJson('/auth/impersonation/stop')->assertStatus(409);
    }
    public function test_actor_password_changes_and_target_deactivation_fail_closed(): void {
        $admin=$this->user('admin');$candidate=$this->candidate();$this->login($admin);
        $this->postJson('/auth/impersonation/start',['type'=>'candidate','id'=>$candidate->id])->assertOk();
        DB::table('candidates')->where('id',$candidate->id)->update(['profile_status'=>'inactive']);
        // Refresh the in-memory test guard, as a new HTTP request does in production.
        \Illuminate\Support\Facades\Auth::guard('candidate')->setUser($candidate->fresh());
        $this->getJson('/candidate-api/profile')->assertStatus(409);
        $this->assertAuthenticatedAs($admin,'web');
        DB::table('candidates')->where('id',$candidate->id)->update(['profile_status'=>'active']);
        $this->login($admin);$this->postJson('/auth/impersonation/start',['type'=>'candidate','id'=>$candidate->id])->assertOk();
        $admin->forceFill(['password'=>'ChangedPassword123'])->save();
        $this->postJson('/auth/impersonation/stop')->assertUnauthorized();
        $this->assertGuest('web');$this->assertGuest('candidate');
    }
    public function test_domain_permission_is_required_even_for_user_managers(): void {
        $admin=$this->user('admin');$candidate=$this->candidate();$this->login($admin);
        DB::table('user_permissions')->insert(['user_id'=>$admin->id,'permission_id'=>DB::table('permissions')->where('key','candidates.view')->value('id'),'allowed'=>false]);
        $this->postJson('/auth/impersonation/start',['type'=>'candidate','id'=>$candidate->id])->assertForbidden();
    }
    public function test_start_and_return_require_csrf_tokens(): void {
        $this->app->bind(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, fn($app)=>new class($app,$app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken {
            protected function runningUnitTests() { return false; }
        });
        $admin=$this->user('admin');$candidate=$this->candidate();$this->login($admin);
        $this->postJson('/auth/impersonation/start',['type'=>'candidate','id'=>$candidate->id])->assertStatus(419);
        $token=$this->getJson('/auth/csrf-token')->json('csrf_token');
        $response=$this->postJson('/auth/impersonation/start',['type'=>'candidate','id'=>$candidate->id],['X-CSRF-TOKEN'=>$token])->assertOk();
        $newToken=$response->json('csrf_token');$this->assertNotSame($token,$newToken);
        $this->postJson('/auth/impersonation/stop',[],['X-CSRF-TOKEN'=>$token])->assertStatus(419);
        $this->postJson('/auth/impersonation/stop',[],['X-CSRF-TOKEN'=>$newToken])->assertOk();
        $this->assertAuthenticatedAs($admin,'web');
    }
    public function test_deleted_original_admin_cannot_be_restored(): void {
        $admin=$this->user('admin');$candidate=$this->candidate();$this->login($admin);
        $this->postJson('/auth/impersonation/start',['type'=>'candidate','id'=>$candidate->id])->assertOk();
        $admin->delete();
        $this->postJson('/auth/impersonation/stop')->assertUnauthorized();
        $this->assertGuest('web');$this->assertGuest('candidate');
        $this->assertDatabaseHas('audit_logs',['action'=>'impersonation.revoked','actor_user_id'=>null]);
    }
}
