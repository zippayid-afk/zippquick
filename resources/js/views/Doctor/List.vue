<template>
  <div class="list-page">
    <div class="page-head">
      <h3 class="page-head-title">Doctors List</h3>
      <p class="text-muted">All approved and active doctors</p>
      <button class="btn btn-primary" @click="showAddModal = true">
        <i class="fas fa-plus"></i> Add New Doctor
      </button>
    </div>

    <div class="card">
      <div class="card-body">
        <div class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                <th>Doctor Name</th>
                <th>Email</th>
                <th>Mobile</th>
                <th>Specialization</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="doctor in doctors" :key="doctor.id">
                <td>{{ doctor.full_name }}</td>
                <td>{{ doctor.email }}</td>
                <td>{{ doctor.mobile }}</td>
                <td>{{ doctor.specialization }}</td>
                <td>
                  <span class="badge" :class="doctor.is_active ? 'bg-success' : 'bg-warning'">
                    {{ doctor.is_active ? 'Active' : 'Inactive' }}
                  </span>
                </td>
                <td>
                  <button class="btn btn-sm btn-primary me-2" @click="viewDoctor(doctor.id)">
                    <i class="fas fa-eye"></i> View
                  </button>
                  <button class="btn btn-sm btn-warning" @click="toggleStatus(doctor.id)">
                    <i class="fas fa-toggle-on"></i> Toggle
                  </button>
                </td>
              </tr>
              <tr v-if="doctors.length === 0">
                <td colspan="6" class="text-center">No doctors found</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'DoctorsList',
  data() {
    return {
      doctors: [],
      loading: false,
    };
  },
  mounted() {
    this.fetchDoctors();
  },
  methods: {
    async fetchDoctors() {
      this.loading = true;
      try {
        const response = await this.$axios.get('/api/doctors', {
          params: { status: 'approved' }
        });
        this.doctors = response.data.data || [];
      } catch (error) {
        console.error('Error fetching doctors:', error);
      } finally {
        this.loading = false;
      }
    },
    viewDoctor(id) {
      this.$router.push(`/doctors/${id}`);
    },
    async toggleStatus(id) {
      try {
        await this.$axios.post('/api/doctors/toggle_status', { id });
        this.$notify({ type: 'success', title: 'Success', message: 'Status updated' });
        this.fetchDoctors();
      } catch (error) {
        console.error('Error toggling status:', error);
      }
    },
  },
};
</script>
