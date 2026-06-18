<template>
    <AppLayout title="Companies">
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <DataTable
                    title="Company Management"
                    subtitle="Manage companies in the system"
                    search-placeholder="Search companies by name or code..."
                    empty-message="No companies found. Create your first company to get started."
                    :search="pagination.search.value"
                    :data="pagination.data.value"
                    :current-page="pagination.currentPage.value"
                    :last-page="pagination.lastPage.value"
                    :per-page="pagination.perPage.value"
                    :showing-text="pagination.showingText.value"
                    :is-loading="pagination.isLoading.value"
                    @update:search="pagination.search.value = $event"
                    @go-to-page="pagination.goToPage"
                    @change-per-page="pagination.changePerPage"
                >
                    <template #actions>
                        <button
                            v-if="hasPermission('companies.create')"
                            @click="openCreateModal"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            <span>Create Company</span>
                        </button>
                    </template>

                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Company</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Code</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="company in data" :key="company.id" class="hover:bg-gray-50 transition-colors dark:hover:bg-gray-700">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="h-10 w-10 rounded-full flex items-center justify-center shadow-sm overflow-hidden flex-shrink-0">
                                        <img v-if="company.logo" :src="`/serve-storage/${company.logo}`" :alt="company.name" class="h-10 w-10 object-cover rounded-full" />
                                        <div v-else class="h-10 w-10 bg-gradient-to-br from-green-500 to-green-600 rounded-full flex items-center justify-center">
                                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ company.name }}</div>
                                        <div class="text-sm text-gray-500 dark:text-gray-300">{{ company.description || 'No description' }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200">
                                    {{ company.code }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span :class="company.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                                      class="inline-flex px-2 py-1 text-xs font-semibold rounded-full">
                                    {{ company.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex justify-end space-x-1">
                                    <button
                                        v-if="hasPermission('companies.edit')"
                                        @click="editCompany(company)"
                                        class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors"
                                        title="Edit Company"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                    <button
                                        v-if="hasPermission('companies.delete')"
                                        @click="deleteCompany(company)"
                                        class="p-2 text-red-600 hover:text-red-900 hover:bg-red-50 rounded-full transition-colors"
                                        title="Delete Company"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeModal"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-lg p-6 border border-gray-100 transform transition-all dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">
                            {{ isEditing ? 'Edit Company' : 'Create Company' }}
                        </h3>
                        <button @click="closeModal" class="text-gray-400 hover:text-gray-600 transition-colors dark:text-gray-400">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitForm" class="space-y-5">
                        <!-- Logo Upload -->
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2 dark:text-gray-300">Company Logo</label>
                            <div class="flex items-center space-x-4">
                                <!-- Preview -->
                                <div class="h-16 w-16 rounded-full overflow-hidden flex-shrink-0 bg-gray-100 dark:bg-gray-700 flex items-center justify-center border-2 border-dashed border-gray-300 dark:border-gray-600">
                                    <img v-if="logoPreview" :src="logoPreview" alt="Logo preview" class="h-16 w-16 object-cover rounded-full" />
                                    <svg v-else class="w-7 h-7 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                    </svg>
                                </div>
                                <div class="flex-1 space-y-2">
                                    <label class="cursor-pointer inline-flex items-center space-x-2 px-3 py-1.5 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 text-sm font-medium rounded-lg transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                                        </svg>
                                        <span>Upload Logo</span>
                                        <input ref="logoInput" type="file" accept="image/*" class="hidden" @change="onLogoChange" />
                                    </label>
                                    <p class="text-xs text-gray-400 dark:text-gray-500">PNG, JPG, GIF up to 2MB</p>
                                    <button v-if="logoPreview" type="button" @click="removeLogoFn"
                                            class="text-xs text-red-500 hover:text-red-700 underline">
                                        Remove logo
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Company Name</label>
                            <input v-model="form.name" type="text" required
                                   class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Company Code</label>
                            <input v-model="form.code" type="text" required
                                   class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Description</label>
                            <textarea v-model="form.description" rows="3"
                                      class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600"></textarea>
                        </div>
                        <div v-if="isEditing" class="flex items-center">
                            <input v-model="form.is_active" type="checkbox" id="is_active" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600">
                            <label for="is_active" class="ml-2 text-sm font-medium text-gray-700 dark:text-gray-300">Active Company</label>
                        </div>
                        <div class="flex justify-end space-x-3 pt-6 border-t mt-6">
                            <button type="button" @click="closeModal"
                                    class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                                Cancel
                            </button>
                            <button type="submit"
                                    class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 shadow-md transition-all">
                                {{ isEditing ? 'Update' : 'Create' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, onMounted, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'

const props = defineProps({
    companies: Object
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { post, put, destroy } = useErrorHandler()
const pagination = usePagination(props.companies, 'companies.index')
const { hasPermission } = usePermission()

const showModal = ref(false)
const isEditing = ref(false)
const currentCompany = ref(null)
const logoInput = ref(null)
const logoFile = ref(null)
const logoPreview = ref(null)
const removeLogoFlag = ref(false)

const form = reactive({
    name: '',
    code: '',
    description: '',
    is_active: true
})

onMounted(() => {
    pagination.updateData(props.companies)
})

watch(() => props.companies, (newCompanies) => {
    pagination.updateData(newCompanies)
}, { deep: true })

const onLogoChange = (e) => {
    const file = e.target.files[0]
    if (!file) return
    logoFile.value = file
    removeLogoFlag.value = false
    const reader = new FileReader()
    reader.onload = (ev) => { logoPreview.value = ev.target.result }
    reader.readAsDataURL(file)
}

const removeLogoFn = () => {
    logoFile.value = null
    logoPreview.value = null
    removeLogoFlag.value = true
    if (logoInput.value) logoInput.value.value = ''
}

const resetLogoState = () => {
    logoFile.value = null
    logoPreview.value = null
    removeLogoFlag.value = false
    if (logoInput.value) logoInput.value.value = ''
}

const openCreateModal = () => {
    isEditing.value = false
    currentCompany.value = null
    form.name = ''
    form.code = ''
    form.description = ''
    form.is_active = true
    resetLogoState()
    showModal.value = true
}

const editCompany = (company) => {
    isEditing.value = true
    currentCompany.value = company
    form.name = company.name
    form.code = company.code
    form.description = company.description || ''
    form.is_active = company.is_active
    resetLogoState()
    logoPreview.value = company.logo ? `/serve-storage/${company.logo}` : null
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
    form.name = ''
    form.code = ''
    form.description = ''
    form.is_active = true
    resetLogoState()
}

const submitForm = () => {
    const url = isEditing.value ? `/companies/${currentCompany.value.id}` : '/companies'
    const method = isEditing.value ? 'put' : 'post'

    const data = new FormData()
    data.append('name', form.name)
    data.append('code', form.code)
    data.append('description', form.description || '')
    if (isEditing.value) {
        data.append('is_active', form.is_active ? '1' : '0')
        data.append('remove_logo', removeLogoFlag.value ? '1' : '0')
    }
    if (logoFile.value) {
        data.append('logo', logoFile.value)
    }
    if (method === 'put') {
        data.append('_method', 'PUT')
    }

    router.post(url, data, {
        forceFormData: true,
        onSuccess: () => {
            closeModal()
            showSuccess(isEditing.value ? 'Company updated successfully' : 'Company created successfully')
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred'
            showError(errorMessage)
        }
    })
}

const deleteCompany = async (company) => {
    const confirmed = await confirm({
        title: 'Delete Company',
        message: `Are you sure you want to delete "${company.name}"? This action cannot be undone.`
    })

    if (confirmed) {
        destroy(`/companies/${company.id}`, {
            onSuccess: () => showSuccess('Company deleted successfully'),
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join(', ') || 'Cannot delete company'
                showError(errorMessage)
            }
        })
    }
}
</script>
