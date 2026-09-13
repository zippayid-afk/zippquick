<template>
  <div class="list-page">
    <div class="page-head">
      <h3 class="page-head-title">Clinics</h3>
      <p class="text-muted">All registered clinics</p>
    </div>

    <div class="card">
      <div class="card-body">
        <div class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                <th>Clinic Name</th>
                <th>Doctor</th>
                <th>Address</th>
                <th>Phone</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="clinic in clinics" :key="clinic.id">
                <td>{{ clinic.name }}</td>
                <td>{{ clinic.doctor?.full_name || 'N/A' }}</td>
                <td>{{ clinic.address }}</td>
                <td>{{ clinic.phone }}</td>
                <td>
                  <span class="badge" :class="clinic.is_active ? 'bg-success' : 'bg-secondary'">
                    {{ clinic.is_active ? 'Active' : 'Inactive' }}
                  </span>
                </td>
              </tr>
              <tr v-if="clinics.length === 0">
                <td colspan="5" class="text-center">No clinics found</td>
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
  name: 'Clinics',
  data() {
    return {
      clinics: [],
      loading: false,
    };
  },
  mounted() {
    this.fetchClinics();
  },
  methods: {
    async fetchClinics() {
      this.loading = true;
      try {
        const response = await this.$axios.get('/api/clinics');
        this.clinics = response.data.data || [];
      } catch (error) {
        console.error('Error fetching clinics:', error);
      } finally {
        this.loading = false;
      }
    },
  },
};
</script>
