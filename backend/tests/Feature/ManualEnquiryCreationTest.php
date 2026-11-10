<?php

namespace Tests\Feature;

use App\Models\Enquiry;
use App\Models\EnquiryActivity;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualEnquiryCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $roleName)->value('id'));
        return $user;
    }

    private function property(User $owner, array $overrides = []): Property
    {
        return Property::create(array_merge([
            'owner_id' => $owner->id,
            'title' => 'Manual Enquiry Property',
            'slug' => 'manual-enquiry-' . uniqid(),
            'property_type' => 'apartment',
            'listing_type' => 'sale',
            'price' => 5000000,
            'city' => 'Jaipur',
            'state' => 'Rajasthan',
            'country' => 'India',
            'approval_status' => 'approved',
            'publication_status' => 'published',
            'approved_at' => now(),
            'published_at' => now(),
        ], $overrides));
    }

    private function payload(Property $property, array $overrides = []): array
    {
        return array_merge([
            'property_id' => $property->id,
            'customer_name' => 'Rahul Sharma',
            'customer_phone' => '9876543210',
            'customer_email' => 'rahul@example.com',
            'source' => 'phone_call',
            'message' => 'Interested in the property and wants a callback.',
        ], $overrides);
    }

    public function test_admin_can_create_manual_enquiry_and_leave_it_unassigned(): void
    {
        $admin = $this->userWithRole('admin');
        $owner = $this->userWithRole('owner');
        $property = $this->property($owner);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/enquiries', $this->payload($property))
            ->assertCreated()
            ->assertJsonPath('data.customer_name', 'Rahul Sharma')
            ->assertJsonPath('data.customer_id', null)
            ->assertJsonPath('data.assigned_agent_id', null)
            ->assertJsonPath('data.source', 'phone_call');

        $enquiryId = $response->json('data.id');
        $this->assertDatabaseHas('enquiries', [
            'id' => $enquiryId,
            'property_id' => $property->id,
            'customer_id' => null,
            'customer_phone' => '9876543210',
            'source' => 'phone_call',
        ]);
        $this->assertDatabaseHas('enquiry_activities', [
            'enquiry_id' => $enquiryId,
            'user_id' => $admin->id,
            'activity_type' => 'created',
            'subject' => 'Manual enquiry created',
        ]);
    }

    public function test_admin_can_assign_manual_enquiry_to_active_agent_or_staff(): void
    {
        $admin = $this->userWithRole('admin');
        $owner = $this->userWithRole('owner');
        $agent = $this->userWithRole('agent');
        $property = $this->property($owner);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/enquiries', $this->payload($property, [
                'assigned_user_id' => $agent->id,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.assigned_agent.id', $agent->id);
    }

    public function test_agent_creates_enquiry_assigned_to_self_only_for_own_property(): void
    {
        $agent = $this->userWithRole('agent');
        $owner = $this->userWithRole('owner');
        $property = $this->property($owner, ['assigned_agent_id' => $agent->id]);

        $this->actingAs($agent, 'sanctum')
            ->postJson('/api/v1/enquiries', $this->payload($property))
            ->assertCreated()
            ->assertJsonPath('data.assigned_agent.id', $agent->id);

        $this->assertDatabaseHas('enquiries', [
            'property_id' => $property->id,
            'assigned_agent_id' => $agent->id,
            'customer_name' => 'Rahul Sharma',
        ]);
    }

    public function test_agent_cannot_create_enquiry_for_another_agents_property(): void
    {
        $agent = $this->userWithRole('agent');
        $anotherAgent = $this->userWithRole('agent');
        $owner = $this->userWithRole('owner');
        $property = $this->property($owner, ['assigned_agent_id' => $anotherAgent->id]);

        $this->actingAs($agent, 'sanctum')
            ->postJson('/api/v1/enquiries', $this->payload($property))
            ->assertNotFound();
    }

    public function test_staff_can_create_manual_enquiry_assigned_to_self(): void
    {
        $staff = $this->userWithRole('staff');
        $owner = $this->userWithRole('owner');
        $property = $this->property($owner);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/enquiries', $this->payload($property, [
                'source' => 'walk_in',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.assigned_agent.id', $staff->id)
            ->assertJsonPath('data.source', 'walk_in');
    }

    public function test_owner_cannot_create_manual_enquiry(): void
    {
        $owner = $this->userWithRole('owner');
        $property = $this->property($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/enquiries', $this->payload($property))
            ->assertForbidden();

        $this->assertSame(0, Enquiry::count());
    }

    public function test_non_admin_cannot_choose_another_assignee(): void
    {
        $agent = $this->userWithRole('agent');
        $anotherAgent = $this->userWithRole('agent');
        $owner = $this->userWithRole('owner');
        $property = $this->property($owner, ['assigned_agent_id' => $agent->id]);

        $this->actingAs($agent, 'sanctum')
            ->postJson('/api/v1/enquiries', $this->payload($property, [
                'assigned_user_id' => $anotherAgent->id,
            ]))
            ->assertUnprocessable();
    }
}
