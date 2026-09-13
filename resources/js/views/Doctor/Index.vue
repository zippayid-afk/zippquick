<template>
  <div class="list-page">
    <div class="page-head">
      <h3 class="page-head-title">Doctor Management</h3>
      <p class="text-muted">Manage healthcare professionals and appointments</p>
    </div>

    <!-- Management Cards Grid -->
    <div class="card-grid management-grid">
      <!-- New Doctor Requests -->
      <router-link to="/doctors/requests" class="management-card pending-card">
        <div class="card-icon-wrapper bg-warning">
          <i class="fas fa-user-clock"></i>
        </div>
        <div class="card-content">
          <h4 class="card-title">New Doctor Requests</h4>
          <div class="card-count">{{ statistics.pendingRequests }}</div>
          <p class="card-desc">Pending Approval</p>
        </div>
        <div class="card-arrow">
          <i class="fas fa-chevron-right"></i>
        </div>
      </router-link>

      <!-- Add Doctor -->
      <div class="management-card add-card" @click="showAddDoctorModal = true">
        <div class="card-icon-wrapper bg-primary">
          <i class="fas fa-user-plus"></i>
        </div>
        <div class="card-content">
          <h4 class="card-title">Add Doctor</h4>
          <p class="card-desc">Register new doctor</p>
        </div>
        <div class="card-arrow">
          <i class="fas fa-chevron-right"></i>
        </div>
      </div>

      <!-- Doctors List -->
      <router-link to="/doctors/list" class="management-card list-card">
        <div class="card-icon-wrapper bg-success">
          <i class="fas fa-user-md"></i>
        </div>
        <div class="card-content">
          <h4 class="card-title">Doctors List</h4>
          <div class="card-count">{{ statistics.totalDoctors }}</div>
          <p class="card-desc">All Active Doctors</p>
        </div>
        <div class="card-arrow">
          <i class="fas fa-chevron-right"></i>
        </div>
      </router-link>

      <!-- Denied Doctors -->
      <router-link to="/doctors/denied" class="management-card denied-card">
        <div class="card-icon-wrapper bg-danger">
          <i class="fas fa-user-times"></i>
        </div>
        <div class="card-content">
          <h4 class="card-title">Denied Doctors</h4>
          <div class="card-count">{{ statistics.deniedDoctors }}</div>
          <p class="card-desc">Rejected Applications</p>
        </div>
        <div class="card-arrow">
          <i class="fas fa-chevron-right"></i>
        </div>
      </router-link>

      <!-- Doctor Categories -->
      <router-link to="/doctors/categories" class="management-card category-card">
        <div class="card-icon-wrapper bg-info">
          <i class="fas fa-tags"></i>
        </div>
        <div class="card-content">
          <h4 class="card-title">Doctor Categories</h4>
          <div class="card-count">{{ statistics.doctorCategories }}</div>
          <p class="card-desc">Specializations</p>
        </div>
        <div class="card-arrow">
          <i class="fas fa-chevron-right"></i>
        </div>
      </router-link>

      <!-- In-House Categories -->
      <router-link to="/doctors/inhouse-categories" class="management-card inhouse-card">
        <div class="card-icon-wrapper bg-purple">
          <i class="fas fa-home-lg-alt"></i>
        </div>
        <div class="card-content">
          <h4 class="card-title">In-House Categories</h4>
          <div class="card-count">{{ statistics.inhouseCategories }}</div>
          <p class="card-desc">Home Visit Types</p>
        </div>
        <div class="card-arrow">
          <i class="fas fa-chevron-right"></i>
        </div>
      </router-link>

      <!-- Appointments -->
      <router-link to="/appointments" class="management-card appointment-card">
        <div class="card-icon-wrapper bg-cyan">
          <i class="fas fa-calendar-check"></i>
        </div>
        <div class="card-content">
          <h4 class="card-title">Appointments</h4>
          <div class="card-count">{{ statistics.totalAppointments }}</div>
          <p class="card-desc">All Appointments</p>
        </div>
        <div class="card-arrow">
          <i class="fas fa-chevron-right"></i>
        </div>
      </router-link>

      <!-- Clinics -->
      <router-link to="/doctors/clinics" class="management-card clinic-card">
        <div class="card-icon-wrapper bg-teal">
          <i class="fas fa-hospital"></i>
        </div>
        <div class="card-content">
          <h4 class="card-title">Clinics</h4>
          <div class="card-count">{{ statistics.totalClinics }}</div>
          <p class="card-desc">Registered Clinics</p>
        </div>
        <div class="card-arrow">
          <i class="fas fa-chevron-right"></i>
        </div>
      </router-link>
    </div>

    <!-- Quick Stats Overview -->
    <div class="row mt-4">
      <div class="col-md-12">
        <div class="card">
          <div class="card-header">
            <h5 class="mb-0">Quick Statistics</h5>
          </div>
          <div class="card-body">
            <div class="row text-center">
              <div class="col-md-3">
                <div class="stat-box">
                  <i class="fas fa-check-circle text-success fa-2x mb-2"></i>
                  <h4>{{ statistics.approvedDoctors }}</h4>
                  <p class="text-muted">Approved Doctors</p>
                </div>
              </div>
              <div class="col-md-3">
                <div class="stat-box">
                  <i class="fas fa-clock text-warning fa-2x mb-2"></i>
                  <h4>{{ statistics.pendingRequests }}</h4>
                  <p class="text-muted">Pending Approval</p>
                </div>
              </div>
              <div class="col-md-3">
                <div class="stat-box">
                  <i class="fas fa-calendar-alt text-info fa-2x mb-2"></i>
                  <h4>{{ statistics.todayAppointments }}</h4>
                  <p class="text-muted">Today's Appointments</p>
                </div>
              </div>
              <div class="col-md-3">
                <div class="stat-box">
                  <i class="fas fa-dollar-sign text-primary fa-2x mb-2"></i>
                  <h4>{{ statistics.totalRevenue }}</h4>
                  <p class="text-muted">Total Revenue</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Add Doctor Modal (placeholder) -->
    <div v-if="showAddDoctorModal" class="modal-overlay" @click.self="showAddDoctorModal = false">
      <div class="modal-content">
        <div class="modal-header">
          <h5>Add New Doctor</h5>
          <button @click="showAddDoctorModal = false" class="btn-close">&times;</button>
        </div>
        <div class="modal-body">
          <p>Add doctor form coming soon...</p>
          <router-link to="/doctors/list" class="btn btn-primary">
            Go to Doctors List
          </router-link>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'DoctorIndex',
  data() {
    return {
      showAddDoctorModal: false,
      loading: false,
      statistics: {
        totalDoctors: 0,
        approvedDoctors: 0,
        pendingRequests: 0,
        deniedDoctors: 0,
        doctorCategories: 1,
        inhouseCategories: 1,
        totalAppointments: 36,
        todayAppointments: 0,
        totalClinics: 0,
        totalRevenue: '$0',
      },
    };
  },
  mounted() {
    console.log('Doctor Management Dashboard mounted!');
    this.fetchStatistics();
  },
  methods: {
    async fetchStatistics() {
      this.loading = true;
      try {
        const response = await this.$axios.get('/api/doctors/statistics');
        if (response.data.success) {
          this.statistics = {
            ...this.statistics,
            ...response.data.data
          };
        }
      } catch (error) {
        console.error('Error fetching statistics:', error);
        // Use dummy data for now
        this.statistics = {
          totalDoctors: 24,
          approvedDoctors: 20,
          pendingRequests: 3,
          deniedDoctors: 1,
          doctorCategories: 1,
          inhouseCategories: 1,
          totalAppointments: 36,
          todayAppointments: 8,
          totalClinics: 15,
          totalRevenue: '$12,450',
        };
      } finally {
        this.loading = false;
      }
    },
  },
};
</script>

<style scoped>
.management-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 1.25rem;
  margin-bottom: 2rem;
}

.management-card {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.5rem;
  background: var(--app-card-bg);
  border: 1px solid var(--app-card-border);
  border-radius: 12px;
  text-decoration: none;
  color: inherit;
  transition: all 0.2s ease;
  cursor: pointer;
  position: relative;
  overflow: hidden;
}

.management-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
  border-color: var(--bs-primary);
}

.card-icon-wrapper {
  width: 60px;
  height: 60px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.5rem;
  color: white;
  flex-shrink: 0;
}

.bg-warning { background: #f59e0b; }
.bg-primary { background: #3b82f6; }
.bg-success { background: #10b981; }
.bg-danger { background: #ef4444; }
.bg-info { background: #06b6d4; }
.bg-purple { background: #8b5cf6; }
.bg-cyan { background: #0891b2; }
.bg-teal { background: #14b8a6; }

.card-content {
  flex: 1;
  min-width: 0;
}

.card-title {
  font-size: 1rem;
  font-weight: 600;
  margin: 0 0 0.25rem 0;
  color: var(--app-ink);
}

.card-count {
  font-size: 1.75rem;
  font-weight: 700;
  color: var(--bs-primary);
  line-height: 1;
  margin: 0.5rem 0;
}

.card-desc {
  font-size: 0.85rem;
  color: var(--app-muted);
  margin: 0;
}

.card-arrow {
  color: var(--app-muted);
  font-size: 1.25rem;
  transition: transform 0.2s ease;
}

.management-card:hover .card-arrow {
  transform: translateX(4px);
  color: var(--bs-primary);
}

.stat-box {
  padding: 1rem;
}

.stat-box h4 {
  font-size: 2rem;
  font-weight: 700;
  margin: 0.5rem 0;
}

.stat-box p {
  margin: 0;
  font-size: 0.9rem;
}

/* Modal Styles */
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
}

.modal-content {
  background: white;
  border-radius: 12px;
  width: 90%;
  max-width: 500px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1.5rem;
  border-bottom: 1px solid #e5e7eb;
}

.modal-header h5 {
  margin: 0;
  font-size: 1.25rem;
  font-weight: 600;
}

.btn-close {
  background: none;
  border: none;
  font-size: 1.5rem;
  cursor: pointer;
  color: #6b7280;
  padding: 0;
  width: 30px;
  height: 30px;
}

.modal-body {
  padding: 1.5rem;
}

@media (max-width: 768px) {
  .management-grid {
    grid-template-columns: 1fr;
  }
}
</style>
