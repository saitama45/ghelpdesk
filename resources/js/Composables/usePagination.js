import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'

export function usePagination(initialData = {}, routeName = '', extraParams = {}) {
    const search = ref('')
    const perPage = ref(10)
    const currentPage = ref(1)
    const data = ref(initialData.data || [])
    const total = ref(initialData.total || 0)
    const from = ref(initialData.from || 0)
    const to = ref(initialData.to || 0)
    const lastPage = ref(initialData.last_page || 1)
    const isLoading = ref(false)

    const showingText = computed(() => {
        if (total.value === 0) return 'No records found'
        return `Showing ${from.value} to ${to.value} of ${total.value} records`
    })

    const updateData = (newData) => {
        if (newData) {
            data.value = newData.data || []
            total.value = newData.total || 0
            from.value = newData.from || 0
            to.value = newData.to || 0
            currentPage.value = newData.current_page || 1
            lastPage.value = newData.last_page || 1
        }
    }

    const performSearch = (url = null, additionalParams = {}) => {
        const searchUrl = url || route(routeName)
        const globalParams = typeof extraParams === 'function' ? extraParams() : extraParams
        const params = {
            search: search.value,
            per_page: perPage.value,
            page: currentPage.value,
            ...globalParams,
            ...additionalParams
        }

        isLoading.value = true
        router.get(searchUrl, params, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: (page) => {
                const responseData = page.props[routeName.split('.')[0]] || page.props.data
                if (responseData) {
                    updateData(responseData)
                }
            },
            onFinish: () => {
                isLoading.value = false
            }
        })
    }

    const goToPage = (page, url = null, additionalParams = {}) => {
        if (page >= 1 && page <= lastPage.value) {
            currentPage.value = page
            performSearch(url, additionalParams)
        }
    }

    const changePerPage = (newPerPage, url = null, additionalParams = {}) => {
        perPage.value = newPerPage
        currentPage.value = 1
        performSearch(url, additionalParams)
    }

    // Auto-search with debounce
    let searchTimeout
    watch(() => search.value, (newSearch) => {
        clearTimeout(searchTimeout)
        searchTimeout = setTimeout(() => {
            currentPage.value = 1
            performSearch()
        }, 300)
    })

    return {
        search,
        perPage,
        currentPage,
        data,
        total,
        from,
        to,
        lastPage,
        isLoading,
        showingText,
        updateData,
        performSearch,
        goToPage,
        changePerPage
    }
}