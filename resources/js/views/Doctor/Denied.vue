<template>
  <div class="list-page">
    <div class="page-head">
      <h3 class="page-head-title">Denied Doctors</h3>
      <p class="text-muted">Rejected doctor applications</p>
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
                <th>Rejection Reason</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="doctor in deniedDoctors" :key="doctor.id">
                <td>{{ doctor.full_name }}</td>
                <td>{{ doctor.email }}</td>
                <td>{{ doctor.specialization }}</td>
                <td>{{ doctor.rejection_reason || 'N/A' }}</td>
                <td>{{ formatDate(doctor.created_at) }}</td>
              </tr>
              <tr v-if="deniedDoctors.length === 0">
                <td colspan="5" class="text-center">No denied applications</td>
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
  name: 'DeniedDoctors',
  data() {
    return {
      deniedDoctors: [],
      loading: false,
    };
  },
  mounted() {
    this.fetchDeniedDoctors();
  },
  methods: {
    async fetchDeniedDoctors() {
      this.loading = true;
      try {
        const response = await this.$axios.get('/api/doctors');
        this.deniedDoctors = (response.data.data || []).filter(d => d.rejection_reason);
      } catch (error) {
        console.error('Error fetching denied doctors:', error);
      } finally {
        this.loading = false;
      }
    },
    formatDate(date) {
      return new Date(date).toLocaleDateString();
    },
  },
};
</script>
