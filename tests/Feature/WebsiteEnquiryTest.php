<?php

namespace Tests\Feature;

use App\Mail\AdminEventMail;
use App\Models\User;
use Database\Seeders\LocalSuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WebsiteEnquiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_enquiry_is_saved_and_admin_receives_its_link(): void
    {
        Mail::fake();
        config(['mail.admin_notification_email' => 'admin@example.test', 'app.url' => 'https://platform.example.test']);

        $fields = ['name' => 'Asha Sharma', 'organization' => 'Example Industries', 'phone' => '9876543210', 'audience' => 'employer', 'message' => 'We are hiring.'];
        $id = $this->postJson('/public-api/enquiries', $fields)->assertCreated()->json('reference');
        $this->assertDatabaseHas('website_enquiries', ['id' => $id, ...$fields]);
        Mail::assertSent(AdminEventMail::class, function (AdminEventMail $mail) use ($id) {
            $mail->assertHasTo('admin@example.test')->assertSeeInHtml('https://platform.example.test/admin/enquiries?enquiry='.$id);
            $mail->assertDontSeeInHtml('9876543210');

            return true;
        });

        $this->getJson('/admin-api/enquiries')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/admin-api/enquiries')->assertForbidden();

        $this->seed(LocalSuperAdminSeeder::class);
        $this->actingAs(User::where('email', 'exowork@gmail.com')->firstOrFail())
            ->getJson('/admin-api/enquiries?enquiry='.$id)->assertOk()->assertJsonPath('data.0.phone', '9876543210');
    }

    public function test_invalid_enquiry_is_rejected(): void
    {
        $this->postJson('/public-api/enquiries', ['phone' => 'not a number'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'phone', 'audience']);
        $this->assertDatabaseCount('website_enquiries', 0);
    }
    public function test_enquiry_filters_are_paginated_and_summary_counts_remain_global(): void
    {
        $this->seed(LocalSuperAdminSeeder::class);
        $this->actingAs(User::where('email','exowork@gmail.com')->firstOrFail());
        foreach ([['Asha Sharma','employer'],['Ravi Kumar','candidate'],['Training Contact','training-center']] as [$name,$audience]) {
            \Illuminate\Support\Facades\DB::table('website_enquiries')->insert(['name'=>$name,'phone'=>'9876543210','audience'=>$audience,'organization'=>'Example Organization','message'=>'Please call back.','created_at'=>now()]);
        }
        $this->getJson('/admin-api/enquiries?audience=employer&search=Asha&per_page=25')->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.name','Asha Sharma')->assertJsonPath('counts.all',3)->assertJsonPath('counts.candidate',1);
        $this->getJson('/admin-api/enquiries?search=missing')->assertOk()->assertJsonCount(0,'data')->assertJsonPath('counts.all',3);
        $this->getJson('/admin-api/enquiries?search=%25')->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/admin-api/enquiries?audience=invalid')->assertUnprocessable();
        $this->getJson('/admin-api/enquiries?per_page=999')->assertUnprocessable();
    }
}
