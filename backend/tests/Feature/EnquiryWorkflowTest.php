<?php

namespace Tests\Feature;

use App\Models\Enquiry;
use App\Models\EnquiryActivity;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnquiryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    private function createUserWithRole(string $roleName): User
    {
        $user = User::factory()->create();

        $role = Role::where('name', $roleName)->firstOrFail();

        $user->roles()->attach($role->id);

        return $user;
    }

    private function createProperty(User $owner, array $overrides = []): Property
    {
        return Property::create(array_merge([
            'owner_id' => $owner->id,
            'title' => 'Enquiry Workflow Property',
            'slug' => 'enquiry-workflow-' . uniqid(),
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

    private function createEnquiry(
        User $customer,
        Property $property,
        ?User $assignee = null,
        array $overrides = []
    ): Enquiry {
        return Enquiry::create(array_merge([
            'property_id' => $property->id,
            'customer_id' => $customer->id,
            'assigned_agent_id' => $assignee?->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '9876543210',
            'message' => 'Interested in this property.',
            'status' => 'new',
            'source' => 'website',
        ], $overrides));
    }

    public function test_customer_can_create_enquiry_for_published_property(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/enquiries', [
                'property_id' => $property->id,
                'message' => 'I want to know more about this property.',
                'customer_phone' => '9876543210',
            ])
            ->assertCreated()
            ->assertJsonPath('data.customer_id', $customer->id)
            ->assertJsonPath('data.property_id', $property->id)
            ->assertJsonPath('data.status', 'new');

        $this->assertDatabaseHas('enquiries', [
            'customer_id' => $customer->id,
            'property_id' => $property->id,
            'status' => 'new',
        ]);

        $this->assertDatabaseHas('enquiry_activities', [
            'activity_type' => 'created',
            'subject' => 'Enquiry submitted',
            'new_status' => 'new',
        ]);
    }

    public function test_customer_cannot_enquire_about_unpublished_property(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner, [
            'publication_status' => 'draft',
            'published_at' => null,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/enquiries', [
                'property_id' => $property->id,
                'message' => 'Interested in this property.',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('enquiries', [
            'customer_id' => $customer->id,
            'property_id' => $property->id,
        ]);
    }

    public function test_customer_can_only_see_own_enquiries(): void
    {
        $customer = $this->createUserWithRole('customer');
        $anotherCustomer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);

        $ownEnquiry = $this->createEnquiry($customer, $property);
        $otherEnquiry = $this->createEnquiry($anotherCustomer, $property);

        $response = $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/enquiries')
            ->assertOk();

        $ids = collect($response->json('data.data'))
            ->pluck('id')
            ->all();

        $this->assertContains($ownEnquiry->id, $ids);
        $this->assertNotContains($otherEnquiry->id, $ids);
    }

    public function test_owner_can_only_see_enquiries_for_own_properties(): void
    {
        $owner = $this->createUserWithRole('owner');
        $anotherOwner = $this->createUserWithRole('owner');
        $customer = $this->createUserWithRole('customer');

        $ownProperty = $this->createProperty($owner);
        $otherProperty = $this->createProperty($anotherOwner);

        $ownEnquiry = $this->createEnquiry($customer, $ownProperty);
        $otherEnquiry = $this->createEnquiry($customer, $otherProperty);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/enquiries')
            ->assertOk();

        $ids = collect($response->json('data.data'))
            ->pluck('id')
            ->all();

        $this->assertContains($ownEnquiry->id, $ids);
        $this->assertNotContains($otherEnquiry->id, $ids);
    }

    public function test_agent_can_only_see_directly_assigned_enquiries(): void
    {
        $agent = $this->createUserWithRole('agent');
        $anotherAgent = $this->createUserWithRole('agent');
        $owner = $this->createUserWithRole('owner');
        $customer = $this->createUserWithRole('customer');

        // Property belongs to Agent A.
        $assignedProperty = $this->createProperty($owner, [
            'assigned_agent_id' => $agent->id,
        ]);

        // Property is not assigned to either agent.
        $otherProperty = $this->createProperty($owner);

        // Enquiry belongs to Agent A's property,
        // but is not directly assigned to Agent A.
        $propertyAssignedEnquiry = $this->createEnquiry(
            $customer,
            $assignedProperty
        );

        // Directly assigned to Agent A.
        $directlyAssignedEnquiry = $this->createEnquiry(
            $customer,
            $otherProperty,
            $agent
        );

        // Directly assigned to Agent B.
        $anotherAgentsEnquiry = $this->createEnquiry(
            $customer,
            $otherProperty,
            $anotherAgent
        );

        $response = $this->actingAs($agent, 'sanctum')
            ->getJson('/api/v1/enquiries')
            ->assertOk();

        $ids = collect($response->json('data.data'))
            ->pluck('id')
            ->all();

        $this->assertContains($directlyAssignedEnquiry->id, $ids);

        $this->assertNotContains($propertyAssignedEnquiry->id, $ids);

        $this->assertNotContains($anotherAgentsEnquiry->id, $ids);
    }

    public function test_customer_cannot_view_another_customers_enquiry(): void
    {
        $customer = $this->createUserWithRole('customer');
        $anotherCustomer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);

        $enquiry = $this->createEnquiry($anotherCustomer, $property);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/enquiries/{$enquiry->id}")
            ->assertForbidden();
    }

    public function test_customer_can_view_own_enquiry(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);
        $enquiry = $this->createEnquiry($customer, $property);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/enquiries/{$enquiry->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $enquiry->id);
    }

    public function test_owner_can_view_enquiry_for_own_property(): void
    {
        $owner = $this->createUserWithRole('owner');
        $customer = $this->createUserWithRole('customer');

        $property = $this->createProperty($owner);
        $enquiry = $this->createEnquiry($customer, $property);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/enquiries/{$enquiry->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $enquiry->id);
    }

    public function test_admin_can_close_enquiry_and_save_closing_notes(): void
    {
        $admin = $this->createUserWithRole('admin');
        $owner = $this->createUserWithRole('owner');
        $customer = $this->createUserWithRole('customer');

        $property = $this->createProperty($owner);
        $enquiry = $this->createEnquiry($customer, $property);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/enquiries/{$enquiry->id}/status", [
                'status' => 'closed',
                'notes' => 'Customer completed the purchase process.',
                'closing_notes' => 'Enquiry successfully closed.',
                'follow_up_at' => '2026-11-15 10:00:00',
            ])

            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $updatedEnquiry = $enquiry->fresh();

        $this->assertNotNull($updatedEnquiry->closed_at);
        $this->assertEquals(
            'Enquiry successfully closed.',
            $updatedEnquiry->closing_notes
        );

        $this->assertDatabaseHas('enquiry_activities', [
            'enquiry_id' => $enquiry->id,
            'user_id' => $admin->id,
            'activity_type' => 'status_changed',
            'old_status' => 'new',
            'new_status' => 'closed',
        ]);

        $activity = EnquiryActivity::where('enquiry_id', $enquiry->id)
            ->where('activity_type', 'status_changed')
            ->firstOrFail();

        $this->assertNotNull($activity->follow_up_at);
    }

    public function test_owner_can_update_status_for_own_property_enquiry(): void
    {
        $owner = $this->createUserWithRole('owner');
        $customer = $this->createUserWithRole('customer');

        $property = $this->createProperty($owner);
        $enquiry = $this->createEnquiry($customer, $property);

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/enquiries/{$enquiry->id}/status", [
                'status' => 'contacted',
                'notes' => 'Contacted the customer.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'contacted');

        $this->assertNotNull($enquiry->fresh()->last_contacted_at);
    }

    public function test_customer_cannot_update_enquiry_status(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);
        $enquiry = $this->createEnquiry($customer, $property);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/v1/enquiries/{$enquiry->id}/status", [
                'status' => 'contacted',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('enquiries', [
            'id' => $enquiry->id,
            'status' => 'new',
        ]);
    }

    public function test_invalid_enquiry_status_is_rejected(): void
    {
        $admin = $this->createUserWithRole('admin');
        $owner = $this->createUserWithRole('owner');
        $customer = $this->createUserWithRole('customer');

        $property = $this->createProperty($owner);
        $enquiry = $this->createEnquiry($customer, $property);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/enquiries/{$enquiry->id}/status", [
                'status' => 'invalid_status',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('enquiries', [
            'id' => $enquiry->id,
            'status' => 'new',
        ]);
    }

    public function test_owner_can_add_activity_to_own_property_enquiry(): void
    {
        $owner = $this->createUserWithRole('owner');
        $customer = $this->createUserWithRole('customer');

        $property = $this->createProperty($owner);
        $enquiry = $this->createEnquiry($customer, $property);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/enquiries/{$enquiry->id}/activities", [
                'activity_type' => 'call',
                'subject' => 'Customer phone call',
                'notes' => 'Discussed property details.',
                'follow_up_at' => '2026-11-15 10:00:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.activity_type', 'call');

        $this->assertDatabaseHas('enquiry_activities', [
            'enquiry_id' => $enquiry->id,
            'user_id' => $owner->id,
            'activity_type' => 'call',
            'subject' => 'Customer phone call',
        ]);
    }

    public function test_customer_cannot_add_internal_enquiry_activity(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);
        $enquiry = $this->createEnquiry($customer, $property);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/enquiries/{$enquiry->id}/activities", [
                'activity_type' => 'call',
                'subject' => 'Unauthorized activity',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('enquiry_activities', [
            'enquiry_id' => $enquiry->id,
            'subject' => 'Unauthorized activity',
        ]);
    }
    public function test_agent_cannot_view_enquiry_assigned_to_another_agent_on_own_property(): void
    {
        $agentA = $this->createUserWithRole('agent');
        $agentB = $this->createUserWithRole('agent');
        $owner = $this->createUserWithRole('owner');
        $customer = $this->createUserWithRole('customer');

        $property = $this->createProperty($owner, [
            'assigned_agent_id' => $agentA->id,
        ]);

        $enquiry = $this->createEnquiry(
            $customer,
            $property,
            $agentB
        );

        $this->actingAs($agentA, 'sanctum')
            ->getJson("/api/v1/enquiries/{$enquiry->id}")
            ->assertForbidden();
    }

    public function test_agent_cannot_manage_another_agents_enquiry_on_own_property(): void
    {
        $agentA = $this->createUserWithRole('agent');
        $agentB = $this->createUserWithRole('agent');
        $owner = $this->createUserWithRole('owner');
        $customer = $this->createUserWithRole('customer');

        $property = $this->createProperty($owner, [
            'assigned_agent_id' => $agentA->id,
        ]);

        $enquiry = $this->createEnquiry(
            $customer,
            $property,
            $agentB
        );

        $this->actingAs($agentA, 'sanctum')
            ->patchJson("/api/v1/enquiries/{$enquiry->id}/status", [
                'status' => 'contacted',
                'notes' => 'Unauthorized status update attempt.',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('enquiries', [
            'id' => $enquiry->id,
            'assigned_agent_id' => $agentB->id,
            'status' => 'new',
        ]);
    }
}
