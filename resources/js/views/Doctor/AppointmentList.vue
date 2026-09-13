<template>
  <div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
      <div class="col-md-8">
        <h1 class="h3 mb-0">
          <i class="fas fa-calendar-check"></i> Appointment Management
        </h1>
        <p class="text-muted mt-1">Manage doctor appointments and video consultations</p>
      </div>
    </div>

    <!-- Quick Stats -->
    <div class="row mb-4">
      <div class="col-md-4">
        <div class="card border-0 shadow-sm">
          <div class="card-body">
            <h6 class="text-muted mb-2">Pending Appointments</h6>
            <h3 class="mb-0 text-warning">{{ stats.pending }}</h3>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card border-0 shadow-sm">
          <div class="card-body">
            <h6 class="text-muted mb-2">Approved Appointments</h6>
            <h3 class="mb-0 text-info">{{ stats.approved }}</h3>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card border-0 shadow-sm">
          <div class="card-body">
            <h6 class="text-muted mb-2">Completed Appointments</h6>
            <h3 class="mb-0 text-success">{{ stats.completed }}</h3>
          </div>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label">Search Patient</label>
            <input
              v-model="filters.search"
              type="text"
              class="form-control"
              placeholder="Name, email, or phone..."
              @input="fetchAppointments"
            />
          </div>
          <div class="col-md-2">
            <label class="form-label">Type</label>
            <select v-model="filters.type" class="form-select" @change="fetchAppointments">
              <option value="">All Types</option>
              <option value="clinic">Clinic</option>
              <option value="video">Video</option>
              <option value="in_house">In-House</option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Status</label>
            <select v-model="filters.status" class="form-select" @change="fetchAppointments">
              <option value="">All Status</option>
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="completed">Completed</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Doctor</label>
            <select v-model="filters.doctor_id" class="form-select" @change="fetchAppointments">
              <option value="">All Doctors</option>
              <!-- Populate with doctors -->
            </select>
          </div>
          <div class="col-md-2 d-flex align-items-end">
            <button class="btn btn-outline-secondary w-100" @click="resetFilters">
              <i class="fas fa-redo"></i> Reset
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Appointments Table -->
    <div class="card border-0 shadow-sm">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="bg-light">
            <tr>
              <th class="px-4 py-3">Patient</th>
              <th class="px-4 py-3">Doctor</th>
              <th class="px-4 py-3">Date & Time</th>
              <th class="px-4 py-3">Type</th>
              <th class="px-4 py-3">Status</th>
              <th class="px-4 py-3">Google Meet</th>
              <th class="px-4 py-3">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="appointment in appointments" :key="appointment.id" class="border-bottom">
              <td class="px-4 py-3">
                <div>
                  <h6 class="mb-0">{{ appointment.patient_name }}</h6>
                  <small class="text-muted">{{ appointment.patient_email }}</small>
                </div>
              </td>
              <td class="px-4 py-3">{{ appointment.doctor_name }}</td>
              <td class="px-4 py-3">
                <small>
                  {{ formatDate(appointment.appointment_date) }}<br />
                  {{ formatTime(appointment.appointment_time) }}
                </small>
              </td>
              <td class="px-4 py-3">
                <span
                  :class="{
                    'badge bg-primary': appointment.type === 'video',
                    'badge bg-info': appointment.type === 'clinic',
                    'badge bg-secondary': appointment.type === 'in_house',
                  }"
                >
                  <i
                    :class="{
                      'fas fa-video': appointment.type === 'video',
                      'fas fa-hospital': appointment.type === 'clinic',
                      'fas fa-home': appointment.type === 'in_house',
                    }"
                    class="me-1"
                  ></i>
                  {{ appointment.type }}
                </span>
              </td>
              <td class="px-4 py-3">
                <span
                  :class="{
                    'badge bg-warning': appointment.status === 'pending',
                    'badge bg-info': appointment.status === 'approved',
                    'badge bg-success': appointment.status === 'completed',
                    'badge bg-danger': appointment.status === 'cancelled',
                  }"
                >
                  {{ appointment.status }}
                </span>
              </td>
              <td class="px-4 py-3">
                <div v-if="appointment.type === 'video'">
                  <a
                    v-if="appointment.google_meet_link"
                    :href="appointment.google_meet_link"
                    target="_blank"
                    class="badge bg-success text-white"
                    title="Join Google Meet"
                  >
                    <i class="fas fa-video"></i> Join
                  </a>
                  <button
                    v-else
                    class="btn btn-sm btn-outline-primary"
                    @click="generateGoogleMeet(appointment.id)"
                    :disabled="generatingMeet === appointment.id"
                  >
                    <i class="fas fa-plus"></i> Generate
                  </button>
                </div>
                <span v-else class="text-muted small">N/A</span>
              </td>
              <td class="px-4 py-3">
                <div class="btn-group btn-group-sm" role="group">
                  <button
                    class="btn btn-outline-primary"
                    @click="viewAppointment(appointment.id)"
                    title="View"
                  >
                    <i class="fas fa-eye"></i>
                  </button>
                  <button
                    v-if="appointment.status === 'pending'"
                    class="btn btn-outline-success"
                    @click="approveAppointment(appointment.id)"
                    title="Approve"
                  >
                    <i class="fas fa-check"></i>
                  </button>
                  <button
                    v-if="appointment.status === 'approved'"
                    class="btn btn-outline-info"
                    @click="completeAppointment(appointment.id)"
                    title="Complete"
                  >
                    <i class="fas fa-check-double"></i>
                  </button>
                  <button
                    v-if="['pending', 'approved'].includes(appointment.status)"
                    class="btn btn-outline-danger"
                    @click="cancelAppointment(appointment.id)"
                    title="Cancel"
                  >
                    <i class="fas fa-times"></i>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="card-footer bg-light">
        <nav aria-label="Page navigation">
          <ul class="pagination mb-0">
            <li class="page-item" :class="{ disabled: currentPage === 1 }">
              <button class="page-link" @click="previousPage" :disabled="currentPage === 1">
                Previous
              </button>
            </li>
            <li class="page-item active">
              <span class="page-link">Page {{ currentPage }} of {{ totalPages }}</span>
            </li>
            <li class="page-item" :class="{ disabled: currentPage === totalPages }">
              <button class="page-link" @click="nextPage" :disabled="currentPage === totalPages">
                Next
              </button>
            </li>
          </ul>
        </nav>
      </div>
    </div>
  </div>
</template>

<script>
import { defineComponent } from 'vue';
import axios from 'axios';

export default defineComponent({
  name: 'AppointmentList',
  data() {
    return {
      appointments: [],
      currentPage: 1,
      totalPages: 1,
      perPage: 10,
      total: 0,
      loading: false,
      generatingMeet: null,
      stats: {
        pending: 0,
        approved: 0,
        completed: 0,
      },
      filters: {
        search: '',
        type: '',
        status: '',
        doctor_id: '',
      },
    };
  },
  mounted() {
    this.fetchAppointments();
  },
  methods: {
    async fetchAppointments() {
      this.loading = true;
      try {
        const response = await axios.get('/api/appointments', {
          params: {
            page: this.currentPage,
            per_page: this.perPage,
            ...this.filters,
          },
        });

        this.appointments = response.data.data;
        this.total = response.data.total;
        this.totalPages = Math.ceil(this.total / this.perPage);
        this.updateStats();
      } catch (error) {
        console.error('Error fetching appointments:', error);
        this.$notify({
          type: 'error',
          title: 'Error',
          message: 'Failed to fetch appointments',
        });
      } finally {
        this.loading = false;
      }
    },
    updateStats() {
      this.stats.pending = this.appointments.filter((a) => a.status === 'pending').length;
      this.stats.approved = this.appointments.filter((a) => a.status === 'approved').length;
      this.stats.completed = this.appointments.filter((a) => a.status === 'completed').length;
    },
    formatDate(date) {
      return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
      });
    },
    formatTime(time) {
      return new Date(time).toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: true,
      });
    },
    async generateGoogleMeet(appointmentId) {
      this.generatingMeet = appointmentId;
      try {
        const response = await axios.post('/api/appointments/generate_google_meet', {
          id: appointmentId,
        });

        this.$notify({
          type: 'success',
          title: 'Success',
          message: 'Google Meet link generated and sent to patient',
        });

        this.fetchAppointments();
      } catch (error) {
        console.error('Error generating Google Meet link:', error);
        this.$notify({
          type: 'error',
          title: 'Error',
          message: error.response?.data?.message || 'Failed to generate Google Meet link',
        });
      } finally {
        this.generatingMeet = null;
      }
    },
    viewAppointment(appointmentId) {
      this.$router.push(`/appointments/${appointmentId}`);
    },
    async approveAppointment(appointmentId) {
      try {
        await axios.post('/api/appointments/approve', { id: appointmentId });
        this.$notify({
          type: 'success',
          title: 'Success',
          message: 'Appointment approved',
        });
        this.fetchAppointments();
      } catch (error) {
        console.error('Error approving appointment:', error);
        this.$notify({
          type: 'error',
          title: 'Error',
          message: error.response?.data?.message || 'Failed to approve appointment',
        });
      }
    },
    async completeAppointment(appointmentId) {
      try {
        await axios.post('/api/appointments/complete', { id: appointmentId });
        this.$notify({
          type: 'success',
          title: 'Success',
          message: 'Appointment marked as completed',
        });
        this.fetchAppointments();
      } catch (error) {
        console.error('Error completing appointment:', error);
        this.$notify({
          type: 'error',
          title: 'Error',
          message: error.response?.data?.message || 'Failed to complete appointment',
        });
      }
    },
    async cancelAppointment(appointmentId) {
      const reason = prompt('Enter cancellation reason:');
      if (!reason) return;

      try {
        await axios.post('/api/appointments/cancel', {
          id: appointmentId,
          reason,
        });

        this.$notify({
          type: 'success',
          title: 'Success',
          message: 'Appointment cancelled',
        });
        this.fetchAppointments();
      } catch (error) {
        console.error('Error cancelling appointment:', error);
        this.$notify({
          type: 'error',
          title: 'Error',
          message: error.response?.data?.message || 'Failed to cancel appointment',
        });
      }
    },
    resetFilters() {
      this.filters = {
        search: '',
        type: '',
        status: '',
        doctor_id: '',
      };
      this.currentPage = 1;
      this.fetchAppointments();
    },
    nextPage() {
      if (this.currentPage < this.totalPages) {
        this.currentPage++;
        this.fetchAppointments();
      }
    },
    previousPage() {
      if (this.currentPage > 1) {
        this.currentPage--;
        this.fetchAppointments();
      }
    },
  },
});
</script>

<style scoped>
.badge {
  font-size: 0.85rem;
  padding: 0.5rem 0.75rem;
}

.btn-group-sm .btn {
  font-size: 0.875rem;
  padding: 0.375rem 0.5rem;
}

.table {
  font-size: 0.95rem;
}
</style>
