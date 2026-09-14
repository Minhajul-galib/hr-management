<?php

namespace Tests\Feature;

use App\Events\CliftoTalk\ConversationActivity;
use App\Events\CliftoTalk\MessageSent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\CliftoTalkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Clifto Talk: one-to-one and group messaging available across the whole site.
 */
class CliftoTalkTest extends TestCase
{
    use RefreshDatabase;

    private function chatUser(string $name = 'Chat User'): User
    {
        $user = User::factory()->create(['name' => $name]);

        $role = Role::findOrCreate('Chat Member');
        $role->givePermissionTo(Permission::findOrCreate('access clifto talk'));
        $user->assignRole($role);

        return $user;
    }

    private function talk(): CliftoTalkService
    {
        return app(CliftoTalkService::class);
    }

    // ----- access -----

    public function test_the_permission_gates_the_whole_module(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get('/clifto-talk')->assertForbidden();
    }

    public function test_a_permitted_user_sees_the_page(): void
    {
        $this->actingAs($this->chatUser())
            ->get('/clifto-talk')
            ->assertOk();
    }

    public function test_the_unread_badge_is_shared_with_every_page(): void
    {
        $user = $this->chatUser();

        $response = $this->actingAs($user)->get('/clifto-talk');

        $response->assertInertia(
            fn ($page) => $page->where('cliftoTalk.enabled', true)->where('cliftoTalk.unread_total', 0)
        );
    }

    /**
     * Portability guarantee: a host app with no permission package sets
     * `clifto-talk.permission` to null and every signed-in user gets in.
     */
    public function test_a_null_permission_opens_the_module_to_any_authenticated_user(): void
    {
        $stranger = User::factory()->create();

        $this->assertFalse(\App\Services\CliftoTalk::allows($stranger));
        $this->assertFalse(\App\Services\CliftoTalk::allows(null));

        config(['clifto-talk.permission' => null]);

        $this->assertTrue(\App\Services\CliftoTalk::allows($stranger));
        $this->assertFalse(\App\Services\CliftoTalk::allows(null));
    }

    /** Search must not emit Postgres-only SQL on a MySQL or SQLite host. */
    public function test_the_like_operator_follows_the_database_driver(): void
    {
        $operator = \App\Services\CliftoTalk::likeOperator();

        $this->assertContains($operator, ['like', 'ilike']);
        $this->assertSame(
            \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like',
            $operator,
        );

        // Wildcards a user types are escaped rather than treated as operators.
        $this->assertSame('%100\%\_cotton%', \App\Services\CliftoTalk::likeTerm('100%_cotton'));
    }

    public function test_the_route_prefix_is_published_to_the_frontend(): void
    {
        $this->actingAs($this->chatUser())
            ->get('/clifto-talk')
            ->assertInertia(fn ($page) => $page
                ->where('cliftoTalk.prefix', 'clifto-talk')
                ->where('cliftoTalk.brand', 'Clifto Talk')
            );
    }

    // ----- direct threads -----

    public function test_a_direct_thread_is_reused_rather_than_duplicated(): void
    {
        $alice = $this->chatUser('Alice');
        $bob = $this->chatUser('Bob');

        $first = $this->actingAs($alice)
            ->postJson('/clifto-talk/conversations', ['type' => 'direct', 'user_id' => $bob->id])
            ->assertCreated()
            ->json('conversation.id');

        // Bob opening it from his side must land in the same thread.
        $second = $this->actingAs($bob)
            ->postJson('/clifto-talk/conversations', ['type' => 'direct', 'user_id' => $alice->id])
            ->assertCreated()
            ->json('conversation.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Conversation::count());
    }

    public function test_a_direct_thread_is_titled_after_the_other_person(): void
    {
        $alice = $this->chatUser('Alice');
        $bob = $this->chatUser('Bob');

        $conversation = $this->talk()->findOrCreateDirect($alice, $bob);

        $this->actingAs($alice)
            ->getJson("/clifto-talk/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('conversation.title', 'Bob');

        $this->actingAs($bob)
            ->getJson("/clifto-talk/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('conversation.title', 'Alice');
    }

    public function test_you_cannot_message_yourself(): void
    {
        $alice = $this->chatUser('Alice');

        $this->actingAs($alice)
            ->postJson('/clifto-talk/conversations', ['type' => 'direct', 'user_id' => $alice->id])
            ->assertStatus(422);
    }

    // ----- groups -----

    public function test_a_group_puts_its_creator_in_charge(): void
    {
        $lead = $this->chatUser('Lead');
        $member = $this->chatUser('Member');

        $response = $this->actingAs($lead)
            ->postJson('/clifto-talk/conversations', [
                'type' => 'group',
                'name' => 'Yarn Store',
                'user_ids' => [$member->id],
            ])
            ->assertCreated();

        $response->assertJsonPath('conversation.title', 'Yarn Store')
            ->assertJsonPath('conversation.is_group', true)
            ->assertJsonPath('conversation.my_role', 'admin');

        $conversation = Conversation::firstOrFail();
        $this->assertTrue($conversation->isAdmin($lead->id));
        $this->assertFalse($conversation->isAdmin($member->id));
        $this->assertCount(2, $conversation->activeParticipants()->get());
    }

    public function test_only_an_admin_can_rename_a_group(): void
    {
        $lead = $this->chatUser('Lead');
        $member = $this->chatUser('Member');
        $conversation = $this->talk()->createGroup($lead, 'Original', [$member->id]);

        $this->actingAs($member)
            ->post("/clifto-talk/conversations/{$conversation->id}", ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->actingAs($lead)
            ->post("/clifto-talk/conversations/{$conversation->id}", ['name' => 'Renamed'])
            ->assertOk();

        $this->assertSame('Renamed', $conversation->fresh()->name);
    }

    public function test_leaving_a_group_ends_access_and_promotes_a_successor(): void
    {
        $lead = $this->chatUser('Lead');
        $member = $this->chatUser('Member');
        $conversation = $this->talk()->createGroup($lead, 'Store Team', [$member->id]);

        $this->actingAs($lead)
            ->deleteJson("/clifto-talk/conversations/{$conversation->id}/participants/{$lead->id}")
            ->assertOk()
            ->assertJsonPath('left', true);

        $this->actingAs($lead)
            ->getJson("/clifto-talk/conversations/{$conversation->id}")
            ->assertForbidden();

        // The group must not be left without an admin.
        $this->assertTrue($conversation->fresh()->isAdmin($member->id));
    }

    public function test_a_group_member_can_add_colleagues(): void
    {
        $lead = $this->chatUser('Lead');
        $member = $this->chatUser('Member');
        $newcomer = $this->chatUser('Newcomer');
        $conversation = $this->talk()->createGroup($lead, 'Store Team', [$member->id]);

        $this->actingAs($member)
            ->postJson("/clifto-talk/conversations/{$conversation->id}/participants", [
                'user_ids' => [$newcomer->id],
            ])
            ->assertOk();

        $this->assertTrue($conversation->fresh()->hasActiveParticipant($newcomer->id));

        // The join is announced in the thread.
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'type' => Message::TYPE_SYSTEM,
        ]);
    }

    // ----- messaging -----

    public function test_sending_a_message_broadcasts_to_the_thread_and_to_each_member(): void
    {
        Event::fake([MessageSent::class, ConversationActivity::class]);

        $alice = $this->chatUser('Alice');
        $bob = $this->chatUser('Bob');
        $conversation = $this->talk()->findOrCreateDirect($alice, $bob);

        $this->actingAs($alice)
            ->postJson("/clifto-talk/conversations/{$conversation->id}/messages", ['body' => 'Yarn is ready'])
            ->assertCreated()
            ->assertJsonPath('message.body', 'Yarn is ready')
            ->assertJsonPath('message.user.name', 'Alice');

        Event::assertDispatched(MessageSent::class, fn ($e) => $e->conversationId === $conversation->id);
        Event::assertDispatched(
            ConversationActivity::class,
            fn ($e) => $e->senderId === $alice->id
                && in_array($bob->id, $e->participantIds, true)
        );
    }

    public function test_outsiders_can_neither_read_nor_post(): void
    {
        $alice = $this->chatUser('Alice');
        $bob = $this->chatUser('Bob');
        $outsider = $this->chatUser('Outsider');
        $conversation = $this->talk()->findOrCreateDirect($alice, $bob);

        $this->actingAs($outsider)
            ->getJson("/clifto-talk/conversations/{$conversation->id}/messages")
            ->assertForbidden();

        $this->actingAs($outsider)
            ->postJson("/clifto-talk/conversations/{$conversation->id}/messages", ['body' => 'Hello?'])
            ->assertForbidden();
    }

    public function test_an_empty_message_is_rejected(): void
    {
        $alice = $this->chatUser('Alice');
        $bob = $this->chatUser('Bob');
        $conversation = $this->talk()->findOrCreateDirect($alice, $bob);

        $this->actingAs($alice)
            ->postJson("/clifto-talk/conversations/{$conversation->id}/messages", ['body' => '   '])
            ->assertStatus(422);
    }

    public function test_history_pages_backwards_from_the_newest_message(): void
    {
        $alice = $this->chatUser('Alice');
        $bob = $this->chatUser('Bob');
        $conversation = $this->talk()->findOrCreateDirect($alice, $bob);

        foreach (range(1, 35) as $i) {
            $this->talk()->sendMessage($conversation, $alice, "Message {$i}");
        }

        $firstPage = $this->actingAs($bob)
            ->getJson("/clifto-talk/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('has_more', true)
            ->json('messages');

        $this->assertCount(30, $firstPage);
        // Newest page, in ascending order.
        $this->assertSame('Message 35', end($firstPage)['body']);

        $oldest = $firstPage[0]['id'];

        $secondPage = $this->actingAs($bob)
            ->getJson("/clifto-talk/conversations/{$conversation->id}/messages?before={$oldest}")
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->json('messages');

        $this->assertCount(5, $secondPage);
        $this->assertSame('Message 1', $secondPage[0]['body']);
    }

    public function test_a_reply_carries_a_quote_of_its_parent(): void
    {
        $alice = $this->chatUser('Alice');
        $bob = $this->chatUser('Bob');
        $conversation = $this->talk()->findOrCreateDirect($alice, $bob);

        $parent = $this->talk()->sendMessage($conversation, $alice, 'Where is the PI?');

        $this->actingAs($bob)
            ->postJson("/clifto-talk/conversations/{$conversation->id}/messages", [
                'body' => 'Attached below',
                'parent_id' => $parent->id,
            ])
            ->assertCreated()
            ->assertJsonPath('message.parent.body', 'Where is the PI?')
            ->assertJsonPath('message.parent.user_name', 'Alice');
    }

    public function test_a_reply_cannot_point_at_another_thread(): void
    {
        $alice = $this->chatUser('Alice');
        $bob = $this->chatUser('Bob');
        $carol = $this->chatUser('Carol');

        $ours = $this->talk()->findOrCreateDirect($alice, $bob);
        $theirs = $this->talk()->findOrCreateDirect($alice, $carol);
        $foreign = $this->talk()->sendMessage($theirs, $alice, 'Private note');

        $this->actingAs($alice)
            ->postJson("/clifto-talk/conversations/{$ours->id}/messages", [
                'body' => 'Leaking?',
                'parent_id' => $foreign->id,
            ])
            ->assertCreated()
            ->assertJsonPath('message.parent', null);
    }

    // ----- edit, delete, react -----

    public function test_only_the_author_may_edit_their_message(): void
    {
        $alice = $this->chatUser('Alice');
        $bob = $this->chatUser('Bob');
        $conversation = $this->talk()->findOrCreateDirect($alice, $bob);
        $message = $this->talk()->sendMessage($conversation, $alice, 'Typo hree');

        $this->actingAs($bob)
            ->patchJson("/clifto-talk/messages/{$message->id}", ['body' => 'Hacked'])
            ->assertForbidden();

        $this->actingAs($alice)
            ->patchJson("/clifto-talk/messages/{$message->id}", ['body' => 'Typo here'])
            ->assertOk()
            ->assertJsonPath('message.body', 'Typo here');

        $this->assertNotNull($message->fresh()->edited_at);
    }

    public function test_a_deleted_message_leaves_a_tombstone_rather_than_a_gap(): void
    {
        $alice = $this->chatUser('Alice');
        $bob = $this->chatUser('Bob');
        $conversation = $this->talk()->findOrCreateDirect($alice, $bob);
        $message = $this->talk()->sendMessage($conversation, $alice, 'Sensitive');

        $this->actingAs($alice)
            ->deleteJson("/clifto-talk/messages/{$message->id}")
            ->assertOk()
            ->assertJsonPath('message.is_deleted', true)
            ->assertJsonPath('message.body', null);

        // Still listed, but with the body withheld.
        $body = $this->actingAs($bob)
            ->getJson("/clifto-talk/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->json('messages.0');

        $this->assertTrue($body['is_deleted']);
        $this->assertNull($body['body']);
    }

    public function test_a_group_admin_can_remove_someone_elses_message(): void
    {
        $lead = $this->chatUser('Lead');
        $member = $this->chatUser('Member');
        $conversation = $this->talk()->createGroup($lead, 'Store Team', [$member->id]);
        $message = $this->talk()->sendMessage($conversation, $member, 'Oops');

        $this->actingAs($lead)
            ->deleteJson("/clifto-talk/messages/{$message->id}")
            ->assertOk();

        $this->assertSoftDeleted('messages', ['id' => $message->id]);
    }

    public function test_reacting_twice_with_the_same_emoji_removes_the_reaction(): void
    {
        $alice = $this->chatUser('Alice');
        $bob = $this->chatUser('Bob');
        $conversation = $this->talk()->findOrCreateDirect($alice, $bob);
        $message = $this->talk()->sendMessage($conversation, $alice, 'Shipment landed');

        $this->actingAs($bob)
            ->postJson("/clifto-talk/messages/{$message->id}/reactions", ['emoji' => '🎉'])
            ->assertOk()
            ->assertJsonPath('message.reactions.0.emoji', '🎉')
            ->assertJsonPath('message.reactions.0.count', 1);

        $this->actingAs($bob)
            ->postJson("/clifto-talk/messages/{$message->id}/reactions", ['emoji' => '🎉'])
            ->assertOk()
            ->assertJsonCount(0, 'message.reactions');
    }

    // ----- unread and read receipts -----

    public function test_unread_counts_ignore_your_own_messages(): void
    {
        $alice = $this->chatUser('Alice');
        $bob = $this->chatUser('Bob');
        $conversation = $this->talk()->findOrCreateDirect($alice, $bob);

        $this->talk()->sendMessage($conversation, $alice, 'One');
        $this->talk()->sendMessage($conversation, $alice, 'Two');

        $this->actingAs($bob)
            ->getJson('/clifto-talk/unread-count')
            ->assertJsonPath('unread_total', 2);

        $this->actingAs($alice)
            ->getJson('/clifto-talk/unread-count')
            ->assertJsonPath('unread_total', 0);
    }

    public function test_marking_read_clears_the_badge_and_moves_the_watermark(): void
    {
        $alice = $this->chatUser('Alice');
        $bob = $this->chatUser('Bob');
        $conversation = $this->talk()->findOrCreateDirect($alice, $bob);
        $last = $this->talk()->sendMessage($conversation, $alice, 'Please confirm');

        $this->actingAs($bob)
            ->postJson("/clifto-talk/conversations/{$conversation->id}/read")
            ->assertOk()
            ->assertJsonPath('last_read_message_id', $last->id)
            ->assertJsonPath('unread_total', 0);

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $bob->id,
            'last_read_message_id' => $last->id,
        ]);
    }

    public function test_the_read_watermark_never_moves_backwards(): void
    {
        $alice = $this->chatUser('Alice');
        $bob = $this->chatUser('Bob');
        $conversation = $this->talk()->findOrCreateDirect($alice, $bob);

        $first = $this->talk()->sendMessage($conversation, $alice, 'First');
        $second = $this->talk()->sendMessage($conversation, $alice, 'Second');

        $this->talk()->markRead($conversation, $bob, $second->id);
        // A late-arriving read for an older message must not undo the newer one.
        $this->talk()->markRead($conversation, $bob, $first->id);

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $bob->id,
            'last_read_message_id' => $second->id,
        ]);
    }

    // ----- attachments -----

    public function test_an_attachment_is_stored_privately_and_served_only_to_members(): void
    {
        Storage::fake('local');

        $alice = $this->chatUser('Alice');
        $bob = $this->chatUser('Bob');
        $outsider = $this->chatUser('Outsider');
        $conversation = $this->talk()->findOrCreateDirect($alice, $bob);

        $response = $this->actingAs($alice)
            ->postJson("/clifto-talk/conversations/{$conversation->id}/messages", [
                'attachments' => [UploadedFile::fake()->image('booking.jpg', 120, 80)],
            ])
            ->assertCreated();

        $attachment = $response->json('message.attachments.0');

        $this->assertTrue($attachment['is_image']);
        $this->assertSame('booking.jpg', $attachment['name']);
        Storage::disk('local')->assertExists(
            \App\Models\MessageAttachment::firstOrFail()->path
        );

        $attachmentId = $attachment['id'];

        $this->actingAs($bob)->get("/clifto-talk/attachments/{$attachmentId}")->assertOk();
        $this->actingAs($outsider)->get("/clifto-talk/attachments/{$attachmentId}")->assertForbidden();
    }

    // ----- search -----

    public function test_search_only_reaches_threads_you_belong_to(): void
    {
        $alice = $this->chatUser('Alice');
        $bob = $this->chatUser('Bob');
        $carol = $this->chatUser('Carol');

        $ours = $this->talk()->findOrCreateDirect($alice, $bob);
        $theirs = $this->talk()->findOrCreateDirect($alice, $carol);

        $this->talk()->sendMessage($ours, $alice, 'Cotton shipment delayed');
        $this->talk()->sendMessage($theirs, $alice, 'Cotton pricing confidential');

        $results = $this->actingAs($bob)
            ->getJson('/clifto-talk/search?q=cotton')
            ->assertOk()
            ->json('results');

        $this->assertCount(1, $results);
        $this->assertSame('Cotton shipment delayed', $results[0]['body']);
    }

    // ----- preferences -----

    public function test_pinning_and_muting_are_per_person(): void
    {
        $alice = $this->chatUser('Alice');
        $bob = $this->chatUser('Bob');
        $conversation = $this->talk()->findOrCreateDirect($alice, $bob);

        $this->actingAs($alice)
            ->patchJson("/clifto-talk/conversations/{$conversation->id}/preferences", [
                'is_pinned' => true,
                'muted' => true,
            ])
            ->assertOk()
            ->assertJsonPath('conversation.is_pinned', true)
            ->assertJsonPath('conversation.is_muted', true);

        // Bob's view of the same thread is untouched.
        $this->actingAs($bob)
            ->getJson("/clifto-talk/conversations/{$conversation->id}")
            ->assertJsonPath('conversation.is_pinned', false)
            ->assertJsonPath('conversation.is_muted', false);
    }
}
