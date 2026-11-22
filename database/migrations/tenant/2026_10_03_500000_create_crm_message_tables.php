<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CRM module — phase 5: the conversation with a lead / customer.
     *
     * Outgoing email, SMS and WhatsApp sent from the CRM, and incoming
     * WhatsApp messages from a number that matches a lead or a customer
     * (copied in by WhatsAppService when the webhook stores them). A
     * WhatsApp row keeps the id of its whatsapp_messages log, whose delivery
     * status (sent / delivered / read / failed) Meta keeps updating.
     */
    public function up(): void
    {
        if (Schema::hasTable('crm_messages')) {
            return;
        }
        Schema::create('crm_messages', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->enum('channel', ['email', 'sms', 'whatsapp'])->index();
            $table->enum('direction', ['out', 'in'])->default('out')->index();
            $table->integer('crm_lead_id')->nullable()->index();
            $table->integer('crm_deal_id')->nullable()->index();
            $table->integer('client_id')->nullable()->index();
            $table->string('to', 191)->nullable();     // email address or phone it went to / came from
            $table->string('subject', 191)->nullable();
            $table->text('body');
            $table->string('status', 20)->default('sent'); // sent | failed | received
            $table->string('error', 500)->nullable();
            $table->integer('whatsapp_message_id')->nullable()->index();
            $table->integer('marketing_template_id')->nullable();
            $table->integer('owner_id')->nullable()->index(); // inbound: who should answer it
            $table->timestamp('read_at')->nullable();        // inbound: when someone opened the conversation
            $table->integer('user_id')->nullable();          // outbound: who sent it
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_messages');
    }
};
