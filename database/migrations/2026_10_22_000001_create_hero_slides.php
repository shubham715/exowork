<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hero_slides', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('subtitle');
            $table->string('image_path')->default('/hero1.png');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        foreach ([
            ['From Opportunity to Long-Term Employment.', 'EXOWORK connects candidates, employers and training partners — helping people move from preparation and opportunity to joining, retention and long-term career growth.<br><br>We don’t just help people to get jobs.<br><strong>We help them build sustainable employment.</strong>'],
            ['Your skills. Your next opportunity.', 'Connect with employers who value your skills and take the next step towards <strong>sustainable employment.</strong>'],
            ['Build a stronger workforce together.', 'Bringing candidates, employers and training partners together — with support from <em>training to long-term career growth.</em>'],
        ] as $order => [$title, $subtitle]) {
            DB::table('hero_slides')->insert(compact('title', 'subtitle') + ['image_path' => '/hero1.png', 'sort_order' => $order, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void { Schema::dropIfExists('hero_slides'); }
};
