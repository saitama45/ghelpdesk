import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'

export function usePagination(initialData = {}, routeName = '', extraParams = {}, options = {}) {
    const searchKey = options.searchKey || 'search'
    const dataKey = options.dataKey || null

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

    const toCamelCase = (str) => str.replace(/[-_](\w)/g, (_, c) => c.toUpperCase())

    const performSearch = (url = null, additionalParams = {}) => {
        const globalParams = typeof extraParams === 'function' ? extraParams() : extraParams
        const params = {
            [searchKey]: search.value,
            per_page: perPage.value,
            page: currentPage.value,
            ...globalParams,
            ...additionalParams
        }
        
        const searchUrl = url || route(routeName, params)

        isLoading.value = true
        router.get(searchUrl, params, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: (page) => {
                let responseData = null

                if (dataKey && page.props[dataKey]) {
                    responseData = page.props[dataKey]
                } else {
                    const routeParts = routeName.split('.')
                    const base = routeParts[0]
                    const underscored = base.replace(/-/g, '_')
                    const potentialKeys = [
                        base,
                        underscored,
                        toCamelCase(base),
                        toCamelCase(underscored),
                        base.replace(/s$/, ''),
                        'forms',
                        'records',
                        'tables',
                        'data',
                    ]

                    for (const key of potentialKeys) {
                        if (page.props[key]) {
                            responseData = page.props[key]
                            break
                        }
                    }
                }

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