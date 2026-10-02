<?php
namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Hash,Password};
use Illuminate\Support\Str;
use Illuminate\Auth\Events\PasswordReset;
class EmployerPasswordController extends Controller {
 private function account(string $email): ?User {
  return User::query()->where('email',$email)->where('status','active')->whereExists(fn($q)=>$q->selectRaw('1')->from('user_roles as ur')->join('roles as r','r.id','=','ur.role_id')->whereColumn('ur.user_id','users.id')->where('r.key','employer'))->first();
 }
 public function email(Request $request) {
  $data=$request->validate(['email'=>['required','email','max:255']]);$email=strtolower(trim($data['email']));
  if($this->account($email)) Password::broker()->sendResetLink(['email'=>$email]);
  return response()->json(['message'=>'If an active employer account uses this email, a password reset link has been sent.']);
 }
 public function reset(Request $request) {
  $data=$request->validate(['email'=>['required','email'],'token'=>['required','string'],'password'=>['required','string','min:8','confirmed']]);$data['email']=strtolower(trim($data['email']));
  if(!$this->account($data['email']))return response()->json(['message'=>'This reset link is invalid or expired.'],422);
  $status=Password::broker()->reset($data,function(User $user,string $password){$user->forceFill(['password'=>Hash::make($password),'remember_token'=>Str::random(60)])->save();event(new PasswordReset($user));});
  if($status!==Password::PASSWORD_RESET)return response()->json(['message'=>__($status)],422);
  return response()->json(['message'=>'Password updated. Sign in with your new password.']);
 }
}
