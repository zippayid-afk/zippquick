<template>
  <div class="list-page">
    <div class="page-head">
      <h3 class="page-head-title">New Doctor Requests</h3>
      <p class="text-muted">Review and approve pending doctor applications</p>
    </div>

    <div class="card">
      <div class="card-body">
        <div class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                <th>Doctor Name</th>
                <th>Email</th>
                <th>Specialization</th>
                <th>License</th>
                <th>Date</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="doctor in pendingDoctors" :key="doctor.id">
                <td>{{ doctor.full_name }}</td>
                <td>{{ doctor.email }}</td>
                <td>{{ doctor.specialization }}</td>
                <td>{{ doctor.license_number }}</td>
                <td>{{ formatDate(doctor.created_at) }}</td>
                <td>
                  <button class="btn btn-sm btn-success me-2" @click="approveDoctor(doctor.id)">
                    <i class="fas fa-check"></i> Approve
                  </button>
                  <button class="btn btn-sm btn-danger" @click="rejectDoctor(doctor.id)">
                    <i class="fas fa-times"></i> Reject
                  </button>
                </td>
              </tr>
              <tr v-if="pendingDoctors.length === 0">
                <td colspan="6" class="text-center">No pending requests</td>
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
  name: 'DoctorRequests',
  data() {
    return {
      pendingDoctors: [],
      loading: false,
    };
  },
  mounted() {
    this.fetchPendingDoctors();
  },
  methods: {
    async fetchPendingDoctors() {
      this.loading = true;
      try {
        const response = await this.$axios.get('/api/doctors', {
          params: { status: 'pending' }
        });
        this.pendingDoctors = response.data.data || [];
      } catch (error) {
        console.error('Error fetching pending doctors:', error);
      } finally {
        this.loading = false;
      }
    },
    async approveDoctor(id) {
      if (!confirm('Are you sure you want to approve this doctor?')) return;
      
      try {
        await this.$axios.post('/api/doctors/approve', { id });
        this.$notify({ type: 'success', title: 'Success', message: 'Doctor approved' });
        this.fetchPendingDoctors();
      } catch (error) {
        console.error('Error approving doctor:', error);
      }
    },
    async rejectDoctor(id) {
      const reason = prompt('Please enter rejection reason:');
      if (!reason) return;
      
      try {
        await this.$axios.post('/api/doctors/reject', { id, reason });
        this.$notify({ type: 'success', title: 'Success', message: 'Doctor rejected' });
        this.fetchPendingDoctors();
      } catch (error) {
        console.error('Error rejecting doctor:', error);
      }
    },
    formatDate(date) {
      return new Date(date).toLocaleDateString();
    },
  },
};
</script>
