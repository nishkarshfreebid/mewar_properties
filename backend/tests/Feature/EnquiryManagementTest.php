<?php

namespace Tests\Feature;

use App\Models\Enquiry;
use App\Models\EnquiryActivity;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnquiryManagementTest extends TestCase
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

    private function createProperty(User $owner): Property
    {
        return Property::create([
            'owner_id' => $owner->id,
            'title' => 'Enquiry Test Property',
            'slug' => 'enquiry-test-' . uniqid(),
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
        ]);
    }

    private function createEnquiry(
        User $customer,
        Property $property,
        ?User $assignee = null
    ): Enquiry {
        return Enquiry::create([
            'property_id' => $property->id,
            'customer_id' => $customer->id,
            'assigned_agent_id' => $assignee?->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '9876543210',
            'message' => 'I am interested in this property.',
            'status' => 'new',
            'source' => 'website',
        ]);
    }

    public function test_admin_can_assign_enquiry_to_agent(): void
    {
        $admin = $this->createUserWithRole('admin');
        $owner = $this->createUserWithRole('owner');
        $customer = $this->createUserWithRole('customer');
        $agent = $this->createUserWithRole('agent');

        $property = $this->createProperty($owner);
        $enquiry = $this->createEnquiry($customer, $property);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/enquiries/{$enquiry->id}/assign", [
                'assigned_user_id' => $agent->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.assigned_agent.id', $agent->id);

        $this->assertDatabaseHas('enquiries', [
            'id' => $enquiry->id,
            'assigned_agent_id' => $agent->id,
        ]);
    }

    public function test_admin_can_assign_enquiry_to_staff(): void
    {
        $admin = $this->createUserWithRole('admin');
        $owner = $this->createUserWithRole('owner');
        $customer = $this->createUserWithRole('customer');
        $staff = $this->createUserWithRole('staff');

        $property = $this->createProperty($owner);
        $enquiry = $this->createEnquiry($customer, $property);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/enquiries/{$enquiry->id}/assign", [
                'assigned_user_id' => $staff->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.assigned_agent.id', $staff->id);

        $this->assertDatabaseHas('enquiries', [
            'id' => $enquiry->id,
            'assigned_agent_id' => $staff->id,
        ]);
    }

    public function test_admin_can_unassign_enquiry(): void
    {
        $admin = $this->createUserWithRole('admin');
        $owner = $this->createUserWithRole('owner');
        $customer = $this->createUserWithRole('customer');
        $agent = $this->createUserWithRole('agent');

        $property = $this->createProperty($owner);
        $enquiry = $this->createEnquiry($customer, $property, $agent);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/enquiries/{$enquiry->id}/assign", [
                'assigned_user_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.assigned_agent', null);

        $this->assertDatabaseHas('enquiries', [
            'id' => $enquiry->id,
            'assigned_agent_id' => null,
        ]);
    }

    public function test_customer_cannot_be_assigned_an_enquiry(): void
    {
        $admin = $this->createUserWithRole('admin');
        $owner = $this->createUserWithRole('owner');
        $customer = $this->createUserWithRole('customer');
        $anotherCustomer = $this->createUserWithRole('customer');

        $property = $this->createProperty($owner);
        $enquiry = $this->createEnquiry($customer, $property);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/enquiries/{$enquiry->id}/assign", [
                'assigned_user_id' => $anotherCustomer->id,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('enquiries', [
            'id' => $enquiry->id,
            'assigned_agent_id' => null,
        ]);
    }

    public function test_owner_cannot_be_assigned_an_enquiry(): void
    {
        $admin = $this->createUserWithRole('admin');
        $owner = $this->createUserWithRole('owner');
        $customer = $this->createUserWithRole('customer');

        $property = $this->createProperty($owner);
        $enquiry = $this->createEnquiry($customer, $property);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/enquiries/{$enquiry->id}/assign", [
                'assigned_user_id' => $owner->id,
            ])
            ->assertUnprocessable();
    }

    public function test_non_admin_cannot_assign_enquiries(): void
    {
        $owner = $this->createUserWithRole('owner');
        $customer = $this->createUserWithRole('customer');
        $agent = $this->createUserWithRole('agent');
        $anotherAgent = $this->createUserWithRole('agent');

        $property = $this->createProperty($owner);
        $enquiry = $this->createEnquiry($customer, $property);

        $this->actingAs($agent, 'sanctum')
            ->putJson("/api/v1/enquiries/{$enquiry->id}/assign", [
                'assigned_user_id' => $anotherAgent->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('enquiries', [
            'id' => $enquiry->id,
            'assigned_agent_id' => null,
        ]);
    }

    public function test_assignment_change_is_recorded_in_activity_history(): void
    {
        $admin = $this->createUserWithRole('admin');
        $owner = $this->createUserWithRole('owner');
        $customer = $this->createUserWithRole('customer');
        $agent = $this->createUserWithRole('agent');

        $property = $this->createProperty($owner);
        $enquiry = $this->createEnquiry($customer, $property);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/enquiries/{$enquiry->id}/assign", [
                'assigned_user_id' => $agent->id,
            ])
            ->assertOk();

        $activity = EnquiryActivity::where('enquiry_id', $enquiry->id)
            ->where('activity_type', 'assignment_changed')
            ->firstOrFail();

        $this->assertEquals($admin->id, $activity->user_id);
        $this->assertEquals('Enquiry assigned', $activity->subject);
        $this->assertEquals(
            $agent->id,
            $activity->metadata['new_assigned_user_id']
        );
        $this->assertNull($activity->metadata['old_assigned_user_id']);
    }

    public function test_staff_can_only_see_directly_assigned_enquiries(): void
    {
        $owner = $this->createUserWithRole('owner');
        $customer = $this->createUserWithRole('customer');
        $staff = $this->createUserWithRole('staff');
        $agent = $this->createUserWithRole('agent');

        $property = $this->createProperty($owner);

        $assignedEnquiry = $this->createEnquiry(
            $customer,
            $property,
            $staff
        );

        $unassignedEnquiry = $this->createEnquiry(
            $customer,
            $property,
            $agent
        );

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/enquiries')
            ->assertOk();

        $ids = collect($response->json('data.data'))
            ->pluck('id')
            ->all();

        $this->assertContains($assignedEnquiry->id, $ids);
        $this->assertNotContains($unassignedEnquiry->id, $ids);
    }
}
