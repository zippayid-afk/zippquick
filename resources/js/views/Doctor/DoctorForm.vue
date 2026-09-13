<template>
  <div class="modal fade" id="doctorModal" tabindex="-1" aria-labelledby="doctorModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="doctorModalLabel">
            {{ doctor ? 'Edit Doctor' : 'Add New Doctor' }}
          </h5>
          <button type="button" class="btn-close" @click="closeModal"></button>
        </div>
        <div class="modal-body">
          <form @submit.prevent="submitForm">
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">First Name</label>
                <input v-model="form.first_name" type="text" class="form-control" required />
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label">Last Name</label>
                <input v-model="form.last_name" type="text" class="form-control" required />
              </div>
            </div>
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">Email</label>
                <input v-model="form.email" type="email" class="form-control" required />
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label">Mobile</label>
                <input v-model="form.mobile" type="text" class="form-control" required />
              </div>
            </div>
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">Specialization</label>
                <input v-model="form.specialization" type="text" class="form-control" required />
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label">Experience (Years)</label>
                <input v-model.number="form.experience_years" type="number" class="form-control" required />
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label">Qualification</label>
              <input v-model="form.qualification" type="text" class="form-control" required />
            </div>
            <div class="mb-3">
              <label class="form-label">License Number</label>
              <input v-model="form.license_number" type="text" class="form-control" required />
            </div>
            <div class="mb-3">
              <label class="form-label">Country</label>
              <select v-model="form.country_id" class="form-select" required>
                <option value="">Select Country</option>
                <!-- Options populated dynamically -->
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Bio</label>
              <textarea v-model="form.bio" class="form-control" rows="3"></textarea>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" @click="closeModal">Close</button>
          <button type="button" class="btn btn-primary" @click="submitForm" :disabled="loading">
            <span v-if="loading" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
            {{ loading ? 'Saving...' : 'Save Doctor' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { defineComponent } from 'vue';
import axios from 'axios';

export default defineComponent({
  name: 'DoctorForm',
  props: {
    doctor: Object,
  },
  data() {
    return {
      form: {
        first_name: '',
        last_name: '',
        email: '',
        mobile: '',
        specialization: '',
        qualification: '',
        license_number: '',
        experience_years: 0,
        bio: '',
        country_id: '',
      },
      loading: false,
    };
  },
  watch: {
    doctor: {
      immediate: true,
      handler(newVal) {
        if (newVal) {
          this.form = { ...newVal };
        } else {
          this.resetForm();
        }
      },
    },
  },
  methods: {
    async submitForm() {
      this.loading = true;
      try {
        const endpoint = this.doctor ? '/api/doctors/update' : '/api/doctors/save';
        const payload = { ...this.form };
        if (this.doctor) {
          payload.id = this.doctor.id;
        }

        await axios.post(endpoint, payload);
        this.$notify({
          type: 'success',
          title: 'Success',
          message: `Doctor ${this.doctor ? 'updated' : 'created'} successfully`,
        });
        this.closeModal();
        this.$emit('save');
      } catch (error) {
        this.$notify({
          type: 'error',
          title: 'Error',
          message: error.response?.data?.message || 'Failed to save doctor',
        });
      } finally {
        this.loading = false;
      }
    },
    closeModal() {
      this.resetForm();
      this.$emit('close');
    },
    resetForm() {
      this.form = {
        first_name: '',
        last_name: '',
        email: '',
        mobile: '',
        specialization: '',
        qualification: '',
        license_number: '',
        experience_years: 0,
        bio: '',
        country_id: '',
      };
    },
  },
});
</script>

<style scoped>
.modal-header {
  background-color: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
}

.modal-body {
  padding: 1.5rem;
}

.form-control:focus,
.form-select:focus {
  border-color: #435ebe;
  box-shadow: 0 0 0 0.2rem rgba(67, 94, 190, 0.25);
}

.btn-primary {
  background-color: #435ebe;
  border-color: #435ebe;
}

.btn-primary:hover {
  background-color: #354aa6;
  border-color: #354aa6;
}
</style>
