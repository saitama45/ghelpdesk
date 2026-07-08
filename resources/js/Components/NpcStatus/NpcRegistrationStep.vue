<template>
    <div class="space-y-3">
        <!-- ══ ORGANIZATION DETAILS ══ -->
        <section class="overflow-hidden rounded-lg border border-blue-100 dark:border-blue-900/40">
            <button type="button" @click="toggle('organization')" class="flex w-full items-center justify-between bg-blue-50/60 px-4 py-2.5 text-left dark:bg-blue-900/10">
                <span class="text-[11px] font-black uppercase tracking-widest text-blue-700 dark:text-blue-300">Organization Details</span>
                <svg class="h-4 w-4 text-blue-600 transition-transform" :class="{ 'rotate-180': open.organization }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div v-show="open.organization" class="space-y-5 p-4">
                <div>
                    <h5 class="mb-2 text-xs font-black text-gray-700 dark:text-gray-200">Organization/Agency Details</h5>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div v-for="f in orgFields" :key="f.key">
                            <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">{{ f.label }} <span v-if="f.optional" class="font-normal normal-case text-gray-400">(Optional)</span></label>
                            <input v-model="model.organization[f.key]" :disabled="!canEdit" :type="f.email ? 'email' : 'text'" :data-invalid="di('reg.organization.' + f.key)" :class="[inputClass, fieldCls('reg.organization.' + f.key, f.email, model.organization[f.key])]">
                            <p v-if="f.email && emailBad(model.organization[f.key])" class="mt-1 text-[11px] font-semibold text-red-600">Enter a valid email address.</p>
                        </div>
                    </div>
                </div>

                <div>
                    <h5 class="mb-2 text-xs font-black text-gray-700 dark:text-gray-200">Sector Details</h5>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div v-for="f in sectorFields" :key="f.key">
                            <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">{{ f.label }}</label>
                            <input v-model="model.sector[f.key]" :disabled="!canEdit" type="text" :data-invalid="di('reg.sector.' + f.key)" :class="[inputClass, cls('reg.sector.' + f.key)]">
                        </div>
                    </div>
                </div>

                <div>
                    <h5 class="mb-2 text-xs font-black text-gray-700 dark:text-gray-200">Head of Organization Details</h5>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div v-for="f in headFields" :key="f.key">
                            <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">{{ f.label }} <span v-if="f.optional" class="font-normal normal-case text-gray-400">(Optional)</span></label>
                            <input v-model="model.head_of_org[f.key]" :disabled="!canEdit" :type="f.email ? 'email' : 'text'" :data-invalid="di('reg.head_of_org.' + f.key)" :class="[inputClass, fieldCls('reg.head_of_org.' + f.key, f.email, model.head_of_org[f.key])]">
                            <p v-if="f.email && emailBad(model.head_of_org[f.key])" class="mt-1 text-[11px] font-semibold text-red-600">Enter a valid email address.</p>
                        </div>
                    </div>
                </div>

                <div>
                    <h5 class="mb-2 text-xs font-black text-gray-700 dark:text-gray-200">Data Protection Officer Details <span class="font-normal text-gray-400">(from Step 2 — read only)</span></h5>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div v-for="f in dpoReadonlyFields" :key="f.key">
                            <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">{{ f.label }}</label>
                            <input :value="dpoProfile[f.key] || ''" type="text" readonly class="block w-full cursor-not-allowed rounded-lg border-gray-200 bg-gray-100 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ══ DATA PROCESSING DETAILS ══ -->
        <section class="overflow-hidden rounded-lg border border-blue-100 dark:border-blue-900/40">
            <button type="button" @click="toggle('dataProcessing')" class="flex w-full items-center justify-between bg-blue-50/60 px-4 py-2.5 text-left dark:bg-blue-900/10">
                <span class="text-[11px] font-black uppercase tracking-widest text-blue-700 dark:text-blue-300">Data Processing Details</span>
                <svg class="h-4 w-4 text-blue-600 transition-transform" :class="{ 'rotate-180': open.dataProcessing }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div v-show="open.dataProcessing" class="space-y-4 p-4">
                <div v-for="(dps, idx) in model.data_processing_systems" :key="idx" class="space-y-4 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div class="flex items-center justify-between">
                        <h5 class="text-xs font-black text-gray-700 dark:text-gray-200">Data Processing System #{{ idx + 1 }}</h5>
                        <button v-if="canEdit && canRemoveDps" type="button" @click="$emit('remove-dps', idx)" class="rounded-full p-1 text-red-500 hover:bg-red-50 hover:text-red-700 dark:hover:bg-red-900/20" title="Remove system">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div v-for="f in dpsFields" :key="f.key" :class="{ 'sm:col-span-2': f.long }">
                            <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">{{ f.label }}</label>
                            <textarea v-if="f.long" v-model="dps[f.key]" :disabled="!canEdit" rows="2" :data-invalid="di('reg.dps.' + idx + '.' + f.key)" :class="[inputClass, cls('reg.dps.' + idx + '.' + f.key)]"></textarea>
                            <input v-else v-model="dps[f.key]" :disabled="!canEdit" type="text" :data-invalid="di('reg.dps.' + idx + '.' + f.key)" :class="[inputClass, cls('reg.dps.' + idx + '.' + f.key)]">
                        </div>
                    </div>

                    <div>
                        <h6 class="mb-2 text-[11px] font-black uppercase tracking-wider text-gray-500 dark:text-gray-300">General Information of the Data Life Cycle</h6>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div v-for="f in lifeCycleFields" :key="f.key" :class="{ 'sm:col-span-2': f.long }">
                                <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">{{ f.label }}</label>
                                <textarea v-if="f.long" v-model="dps.life_cycle[f.key]" :disabled="!canEdit" rows="2" :data-invalid="di('reg.dps.' + idx + '.life_cycle.' + f.key)" :class="[inputClass, cls('reg.dps.' + idx + '.life_cycle.' + f.key)]"></textarea>
                                <input v-else v-model="dps.life_cycle[f.key]" :disabled="!canEdit" type="text" :data-invalid="di('reg.dps.' + idx + '.life_cycle.' + f.key)" :class="[inputClass, cls('reg.dps.' + idx + '.life_cycle.' + f.key)]">
                            </div>
                        </div>
                    </div>

                    <div>
                        <h6 class="mb-2 text-[11px] font-black uppercase tracking-wider text-gray-500 dark:text-gray-300">Description of Security Measures</h6>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div v-for="f in securityFields" :key="f.key" :class="{ 'sm:col-span-2': f.long }">
                                <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">{{ f.label }}</label>
                                <textarea v-if="f.long" v-model="dps.security_measures[f.key]" :disabled="!canEdit" rows="2" :data-invalid="di('reg.dps.' + idx + '.security_measures.' + f.key)" :class="[inputClass, cls('reg.dps.' + idx + '.security_measures.' + f.key)]"></textarea>
                                <input v-else v-model="dps.security_measures[f.key]" :disabled="!canEdit" type="text" :data-invalid="di('reg.dps.' + idx + '.security_measures.' + f.key)" :class="[inputClass, cls('reg.dps.' + idx + '.security_measures.' + f.key)]">
                            </div>
                        </div>
                    </div>
                </div>

                <button v-if="canEdit" type="button" @click="$emit('add-dps')" class="rounded-lg border border-dashed border-blue-300 px-4 py-2 text-xs font-black text-blue-600 hover:bg-blue-50 dark:border-blue-800 dark:hover:bg-blue-900/20">
                    + Add Data Processing System
                </button>
            </div>
        </section>

        <!-- ══ COMPLIANCE OFFICER FOR PRIVACY ══ -->
        <section class="overflow-hidden rounded-lg border border-blue-100 dark:border-blue-900/40">
            <button type="button" @click="toggle('compliance')" class="flex w-full items-center justify-between bg-blue-50/60 px-4 py-2.5 text-left dark:bg-blue-900/10">
                <span class="text-[11px] font-black uppercase tracking-widest text-blue-700 dark:text-blue-300">Compliance Officer for Privacy</span>
                <svg class="h-4 w-4 text-blue-600 transition-transform" :class="{ 'rotate-180': open.compliance }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div v-show="open.compliance" class="p-4">
                <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Compliance Officer for Privacy Details <span class="font-normal normal-case text-gray-400">(Optional)</span></label>
                <textarea v-model="model.compliance_officer" :disabled="!canEdit" rows="2" :class="inputClass"></textarea>
            </div>
        </section>

        <!-- ══ UPLOADING DOCUMENTS ══ -->
        <section class="overflow-hidden rounded-lg border border-blue-100 dark:border-blue-900/40">
            <button type="button" @click="toggle('documents')" class="flex w-full items-center justify-between bg-blue-50/60 px-4 py-2.5 text-left dark:bg-blue-900/10">
                <span class="text-[11px] font-black uppercase tracking-widest text-blue-700 dark:text-blue-300">Uploading Documents</span>
                <svg class="h-4 w-4 text-blue-600 transition-transform" :class="{ 'rotate-180': open.documents }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div v-show="open.documents" class="space-y-4 p-4">
                <h5 class="text-xs font-black text-gray-700 dark:text-gray-200">Supporting Documents</h5>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Classification</label>
                        <input v-model="model.classification" :disabled="!canEdit" type="text" :data-invalid="di('reg.classification')" :class="[inputClass, cls('reg.classification')]">
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Sub-Classification</label>
                        <input v-model="model.sub_classification" :disabled="!canEdit" type="text" :data-invalid="di('reg.sub_classification')" :class="[inputClass, cls('reg.sub_classification')]">
                    </div>
                </div>

                <div class="space-y-2">
                    <div v-for="doc in documentTypes" :key="doc.type" :data-invalid="di('reg.doc.' + doc.type)" class="rounded-lg border p-3 dark:border-gray-700" :class="isInvalid('reg.doc.' + doc.type) ? 'border-red-500 ring-1 ring-red-500' : 'border-gray-200'">
                        <div class="text-[11px] font-bold text-gray-700 dark:text-gray-200">{{ doc.label }} <span class="text-red-500">*</span></div>
                        <div class="mt-1.5 flex flex-wrap items-center gap-2">
                            <template v-if="documents[doc.type]">
                                <a :href="documents[doc.type].url" class="truncate text-xs font-bold text-blue-600 hover:underline">{{ documents[doc.type].name || 'Download' }}</a>
                                <button v-if="canEdit" type="button" @click="$emit('delete', { type: doc.type, id: documents[doc.type].id })" class="text-[11px] font-black text-red-600 hover:text-red-800">Remove</button>
                            </template>
                            <input v-else-if="canEdit" :key="fileKey + '-' + doc.type" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.bmp,.heic,.heif" class="w-full text-xs text-gray-500 file:mr-2 file:rounded-full file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-blue-700 dark:text-gray-300" @change="onFile(doc.type, $event)">
                            <span v-else class="text-xs font-semibold text-gray-400">Not uploaded</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'

const props = defineProps({
    model: { type: Object, required: true },
    dpoProfile: { type: Object, default: () => ({}) },
    documents: { type: Object, default: () => ({}) },
    documentTypes: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
    invalidKeys: { type: Array, default: () => [] },
})

const emit = defineEmits(['upload', 'delete', 'add-dps', 'remove-dps'])

const fileKey = ref(0)

// Kept out of the template to avoid a raw ">" in a binding expression.
const canRemoveDps = computed(() => (props.model?.data_processing_systems?.length || 0) > 1)

// Red-border / scroll-anchor helpers keyed by the same scheme the parent uses.
const invalidSet = computed(() => new Set(props.invalidKeys || []))
const isInvalid = (key) => invalidSet.value.has(key)
const cls = (key) => (isInvalid(key) ? 'border-red-500 ring-1 ring-red-500' : '')
const di = (key) => isInvalid(key) || null

// Live email-format check (during typing).
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const emailBad = (value) => { const s = String(value ?? '').trim(); return s !== '' && !EMAIL_RE.test(s) }
// Combined red-border class for a text/email input.
const fieldCls = (key, isEmail, value) => ((isInvalid(key) || (isEmail && emailBad(value))) ? 'border-red-500 ring-1 ring-red-500' : '')

// If a validation attempt flagged any registration field, expand every section
// so the highlighted (and scrolled-to) field is actually visible.
watch(() => props.invalidKeys, (keys) => {
    if ((keys || []).some((key) => String(key).startsWith('reg.'))) {
        open.organization = true
        open.dataProcessing = true
        open.compliance = true
        open.documents = true
    }
}, { deep: true })

const open = reactive({
    organization: true,
    dataProcessing: false,
    compliance: false,
    documents: false,
})
const toggle = (key) => { open[key] = !open[key] }

const inputClass = 'block w-full rounded-lg border-gray-300 text-sm shadow-sm disabled:cursor-not-allowed disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800'

const orgFields = [
    { key: 'name', label: 'Organization Name' },
    { key: 'website', label: 'Website (URL)', optional: true },
    { key: 'country', label: 'Country' },
    { key: 'address', label: 'Company Address' },
    { key: 'region', label: 'Region' },
    { key: 'province', label: 'Province' },
    { key: 'city', label: 'City / Municipality' },
    { key: 'zip', label: 'Zip Code' },
    { key: 'area_of_coverage', label: 'Area of Coverage' },
    { key: 'email', label: 'Email', email: true },
    { key: 'contact_no', label: 'Contact No.' },
]

const sectorFields = [
    { key: 'sector', label: 'Sector' },
    { key: 'sub_sector', label: 'Sub-Sector' },
]

const headFields = [
    { key: 'first_name', label: 'First Name' },
    { key: 'middle_initial', label: 'Middle Initial', optional: true },
    { key: 'last_name', label: 'Last Name' },
    { key: 'official_designation', label: 'Official Designation' },
    { key: 'email', label: 'Email', email: true },
    { key: 'contact_no', label: 'Contact No.' },
]

// Read-only, sourced from the Step 2 DPO profile object.
const dpoReadonlyFields = [
    { key: 'first_name', label: 'First Name' },
    { key: 'middle_initial', label: 'Middle Initial' },
    { key: 'last_name', label: 'Last Name' },
    { key: 'designation', label: 'Official Designation' },
    { key: 'official_dpo_email', label: 'Official DPO Email' },
    { key: 'telephone_no', label: 'Telephone No.' },
    { key: 'mobile_no', label: 'Mobile No.' },
    { key: 'date_designated_dpo', label: 'Date of Designation as DPO' },
]

const dpsFields = [
    { key: 'is_manual_or_automated', label: 'Is DPS Manual or Automated processing?' },
    { key: 'system_name', label: 'Data Processing System Name' },
    { key: 'basis_of_processing_info', label: 'Basis of Processing Information', long: true },
    { key: 'basis_of_processing_sensitive', label: 'Basis of Processing Sensitive Personal Information', long: true },
    { key: 'purpose', label: 'Purpose for processing', long: true },
    { key: 'data_subjects_categories', label: 'Description of the category or categories of data subjects', long: true },
    { key: 'data_categories', label: 'Description of data or categories of data relating to Data Subjects', long: true },
    { key: 'recipients', label: 'Recipients or categories of recipients to whom the data might be disclosed', long: true },
    { key: 'pic_or_pip', label: 'Is processing done as PIC or PIP?' },
    { key: 'outsourced_or_subcontracted', label: 'Is the system outsourced or subcontracted?' },
]

const lifeCycleFields = [
    { key: 'when_collected', label: 'When is data collected?' },
    { key: 'retention_period', label: 'Retention Period with Reckoning date/time' },
    { key: 'disposal_procedure', label: 'Disposal/Destruction/Deletion Procedure', long: true },
]

const securityFields = [
    { key: 'organizational', label: 'Organizational', long: true },
    { key: 'physical', label: 'Physical', long: true },
    { key: 'technical', label: 'Technical', long: true },
    { key: 'transferred_outside_ph', label: 'Is personal data transferred outside of the Philippines?' },
    { key: 'data_sharing_agreements', label: 'Is there any Data Sharing Agreements with other parties?' },
    { key: 'publicly_facing', label: 'Is the system a publicly facing online mobile or web-based application?' },
    { key: 'external_internal_facing', label: 'Is the system External and/or Internal facing?' },
    { key: 'automated_decision_notification', label: 'Is there any notification regarding any automated decision-making operation and/or profiling?' },
    { key: 'lawful_basis', label: 'Lawful basis of processing personal data', long: true },
    { key: 'other_lawful_basis_info', label: 'Other relevant information pertaining to the specified lawful basis', long: true },
    { key: 'consent_used', label: 'Is consent used as the basis for processing?' },
    { key: 'consent_form', label: 'Consent Form', long: true },
    { key: 'other_consent_proof', label: 'Any other proof of obtaining consent', long: true },
    { key: 'processed_retention_period', label: 'Retention period for the data processed' },
    { key: 'automated_methods_logic', label: 'Methods and logic utilized for automated processing', long: true },
    { key: 'possible_decisions', label: 'Possible decisions relating to the data subject based on the processed data, particularly if they would significantly affect his or her rights and freedoms', long: true },
]

const onFile = (type, event) => {
    const file = event.target.files?.[0]
    if (!file) return
    emit('upload', { type, file })
    fileKey.value++
}
</script>
