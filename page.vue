<script setup>
import { computed, onMounted, ref, watch } from 'vue'

const currencyFormatter = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' })
const cashCurrencyFormatter = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'USD' })
const percentFormatter = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const sections = [
  { id: 'overview', label: 'Übersicht', mark: 'Ü', permissions: ['inventory_view'] },
  { id: 'products', label: 'Produkte', mark: 'P', permissions: ['products_view', 'products_manage', 'product_markup_manage'] },
  { id: 'inventory', label: 'Lager', mark: 'L', permissions: ['inventory_view', 'inventory_manage', 'warehouses_manage'] },
  { id: 'orders', label: 'Bestellungen', mark: 'B', permissions: ['orders_manage'] },
  { id: 'cash', label: 'Kasse', mark: 'K', permissions: ['cash_manage'] },
  { id: 'balance', label: 'Kontostand', mark: '€', permissions: ['balance_view'] },
  { id: 'team', label: 'Mitglieder', mark: 'M', permissions: ['employees_view', 'employees_manage', 'employees_create'] },
  { id: 'roles', label: 'Rollen & Rechte', mark: 'R', permissions: ['roles_manage', 'members_order'] },
]

const permissionOptions = [
  { id: 'employees_view', label: 'Mitglieder ansehen' },
  { id: 'employees_create', label: 'Mitglieder hinzufügen' },
  { id: 'employees_manage', label: 'Mitglieder bearbeiten und deaktivieren' },
  { id: 'roles_manage', label: 'Rollen und Berechtigungen verwalten' },
  { id: 'members_order', label: 'Rangfolge der Rollen ändern' },
  { id: 'inventory_view', label: 'Lager ansehen' },
  { id: 'inventory_manage', label: 'Lagerdaten bearbeiten' },
  { id: 'warehouses_manage', label: 'Lager-Spalten verwalten' },
  { id: 'products_view', label: 'Produkte ansehen' },
  { id: 'products_manage', label: 'Produkte hinzufügen, bearbeiten und löschen' },
  { id: 'product_markup_manage', label: 'Produkt-Aufschlag einstellen' },
  { id: 'orders_manage', label: 'Bestellungen verwalten' },
  { id: 'notice_sections_manage', label: 'Nachrichtenbereiche verwalten und alle Nachrichten bearbeiten' },
  { id: 'cash_manage', label: 'Kassenbuchungen ansehen, erfassen, bearbeiten und löschen' },
  { id: 'balance_view', label: 'Kontostand und Buchungsverlauf ansehen' },
]

function localDateString() {
  const date = new Date()
  const offset = date.getTimezoneOffset() * 60000
  return new Date(date.getTime() - offset).toISOString().slice(0, 10)
}

const session = ref(null)
const csrfToken = ref('')
const activeSection = ref('overview')
const username = ref('')
const password = ref('')
const employees = ref([])
const roles = ref([])
const warehouses = ref([])
const products = ref([])
const noticeSections = ref([])
const notices = ref([])
const noticeDrafts = ref({})
const editingNoticeSectionId = ref(null)
const editingNoticeSectionName = ref('')
const editingNoticeBodies = ref({})
const newNoticeSectionName = ref('')
const orderProducts = ref([])
const savedOrders = ref([])
const cashTransactions = ref([])
const cashForm = ref({ date: localDateString(), name: '', purpose: '', amount: '', type: 'income' })
const cashEditForm = ref(null)
const balanceCents = ref(0)
const balanceTransactions = ref([])
const markupPercent = ref('20,00')
const warehouseName = ref('')
const productForm = ref({ name: '', purchasePrice: '' })
const orderForm = ref({ customerName: '', customerPhone: '', groupingName: '' })
const orderItems = ref([{ key: 1, productId: '', quantity: 1 }])
const nextOrderLineKey = ref(2)
const orderSuccess = ref('')
const employeeForm = ref({ name: '', username: '', password: '', roleId: '' })
const roleForm = ref({ id: '', name: '', permissions: [] })
const loading = ref(true)
const submitting = ref(false)
const savingEmployee = ref(false)
const savingRole = ref(false)
const savingWarehouse = ref(false)
const savingProduct = ref(false)
const savingNotice = ref(false)
const savingNoticeSection = ref(false)
const savingCash = ref(false)
const savingMarkup = ref(false)
const savingRoleOrder = ref(false)
const error = ref('')
const deleteConfirmation = ref(null)
const deleteConfirmationResolver = ref(null)
const userPermissions = computed(() => session.value?.permissions ?? [])
const can = (permission) => userPermissions.value.includes(permission)
const canViewEmployees = computed(() => can('employees_view') || can('employees_manage'))
const canManageEmployees = computed(() => can('employees_manage'))
const canAddEmployees = computed(() => can('employees_create') || canManageEmployees.value)
const canManageRoles = computed(() => can('roles_manage'))
const canManageRoleOrdering = computed(() => can('members_order'))
const canManageWarehouses = computed(() => can('warehouses_manage'))
const canEditWarehouseEntries = computed(() => can('inventory_manage'))
const canManageProducts = computed(() => can('products_manage'))
const canManageProductMarkup = computed(() => can('product_markup_manage'))
const canManageNoticeSections = computed(() => can('notice_sections_manage'))
const canManageCash = computed(() => can('cash_manage'))
const canViewBalance = computed(() => can('balance_view'))
const assignableRoles = computed(() => roles.value.filter((role) => role.assignable))
const availableSections = computed(() =>
  sections.filter((section) =>
    section.id === 'overview' || section.permissions.some((permission) => can(permission)),
  ),
)
const navigationGroups = computed(() => [
  { label: '', sections: availableSections.value.filter(({ id }) => ['overview', 'inventory'].includes(id)) },
  { label: 'Waffenbau', sections: availableSections.value.filter(({ id }) => ['products', 'orders'].includes(id)) },
  { label: 'Finanzen', sections: availableSections.value.filter(({ id }) => ['cash', 'balance'].includes(id)) },
  { label: 'Verwaltung', sections: availableSections.value.filter(({ id }) => ['team', 'roles'].includes(id)) },
].filter(({ sections: groupSections }) => groupSections.length > 0))
const currentSection = computed(() =>
  availableSections.value.find(({ id }) => id === activeSection.value) ?? availableSections.value[0],
)
const roleOrder = computed(() => [...roles.value].sort((a, b) => a.sortOrder - b.sortOrder))
const orderTotalCents = computed(() => orderItems.value.reduce((sum, item) => {
  const product = orderProducts.value.find(({ id }) => id === item.productId)
  if (!product) return sum
  return sum + priceToCents(product.sellingPrice) * Number(item.quantity || 0)
}, 0))

function priceToCents(value) {
  const [whole, fraction = ''] = String(value).replace(',', '.').split('.')
  return Number(whole) * 100 + Number(fraction.padEnd(2, '0').slice(0, 2))
}

function formatCurrency(value) {
  return currencyFormatter.format(Number(value))
}

function formatCurrencyCents(value) {
  return formatCurrency((value / 100).toFixed(2))
}

function formatCashCurrency(value) {
  return cashCurrencyFormatter.format(Number(value))
}

function formatCashCurrencyCents(value) {
  return formatCashCurrency((value / 100).toFixed(2))
}

function formatPercent(value) {
  return percentFormatter.format(Number(String(value).replace(',', '.')))
}

function defaultEmployeeRoleId() {
  return assignableRoles.value.find(({ id }) => id === 'employee')?.id ?? assignableRoles.value[0]?.id ?? ''
}

function confirmDelete(message) {
  deleteConfirmation.value = message
  return new Promise((resolve) => {
    deleteConfirmationResolver.value = resolve
  })
}

function resolveDeleteConfirmation(confirmed) {
  const resolve = deleteConfirmationResolver.value
  deleteConfirmation.value = null
  deleteConfirmationResolver.value = null
  resolve?.(confirmed)
}

async function request(path, options = {}) {
  const response = await fetch(path, {
    credentials: 'same-origin',
    ...options,
    headers: {
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(csrfToken.value ? { 'X-CSRF-Token': csrfToken.value } : {}),
      ...options.headers,
    },
  })
  const data = await response.json()

  if (!response.ok) {
    throw new Error(data.error || 'Die Anfrage konnte nicht verarbeitet werden.')
  }

  return data
}

async function loadSession() {
  error.value = ''
  loading.value = true

  try {
    const data = await request('/api/session')
    session.value = data.user
    csrfToken.value = data.csrfToken
    if (data.user) activeSection.value = availableSections.value[0]?.id ?? ''
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    loading.value = false
  }
}

async function login() {
  error.value = ''
  submitting.value = true

  try {
    const data = await request('/api/login', {
      method: 'POST',
      body: JSON.stringify({ username: username.value, password: password.value }),
    })
    session.value = data.user
    csrfToken.value = data.csrfToken
    password.value = ''
    activeSection.value = availableSections.value[0]?.id ?? ''
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    submitting.value = false
  }
}

async function logout() {
  error.value = ''

  try {
    const data = await request('/api/logout', { method: 'POST' })
    session.value = data.user
    csrfToken.value = data.csrfToken
    activeSection.value = 'overview'
    employees.value = []
    roles.value = []
    products.value = []
    noticeSections.value = []
    notices.value = []
    noticeDrafts.value = {}
    editingNoticeBodies.value = {}
    editingNoticeSectionId.value = null
    editingNoticeSectionName.value = ''
    newNoticeSectionName.value = ''
    warehouses.value = []
    orderProducts.value = []
    savedOrders.value = []
    cashTransactions.value = []
    cashEditForm.value = null
    balanceCents.value = 0
    balanceTransactions.value = []
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function loadTeam() {
  try {
    if (canViewEmployees.value) {
      const data = await request('/api/employees')
      employees.value = data.employees
    }
    if (canAddEmployees.value || canManageEmployees.value || canManageRoles.value || canManageRoleOrdering.value) {
      const data = await request('/api/roles')
      roles.value = data.roles
    }
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function loadWarehouses() {
  try {
    const data = await request('/api/warehouses')
    warehouses.value = data.warehouses.map((warehouse) => ({
      ...warehouse,
      entries: Object.fromEntries(data.members.map((member) => [
        member.id,
        {
          lockerNumber: '',
          pin: '',
          contents: '',
          ...warehouse.entries[member.id],
        },
      ])),
    }))
    employees.value = data.members
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function createWarehouse() {
  error.value = ''
  savingWarehouse.value = true
  try {
    await request('/api/warehouses', {
      method: 'POST',
      body: JSON.stringify({ name: warehouseName.value }),
    })
    warehouseName.value = ''
    await loadWarehouses()
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    savingWarehouse.value = false
  }
}

async function renameWarehouse(warehouse) {
  error.value = ''
  try {
    await request(`/api/warehouses/${warehouse.id}`, {
      method: 'PATCH',
      body: JSON.stringify({ name: warehouse.name }),
    })
    await loadWarehouses()
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function deleteWarehouse(warehouse) {
  if (!await confirmDelete(`Lager „${warehouse.name}“ und alle zugehörigen Einträge löschen?`)) return
  error.value = ''
  try {
    await request(`/api/warehouses/${warehouse.id}`, { method: 'DELETE' })
    await loadWarehouses()
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function saveWarehouseEntry(warehouse, member) {
  error.value = ''
  try {
    await request(`/api/warehouses/${warehouse.id}/members/${member.id}`, {
      method: 'PATCH',
      body: JSON.stringify(warehouse.entries[member.id]),
    })
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function loadProducts() {
  try {
    const data = await request('/api/products')
    products.value = data.products.map((product) => ({
      ...product,
      markupPercent: product.markupPercent.replace('.', ','),
    }))
    markupPercent.value = data.markupPercent.replace('.', ',')
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function createProduct() {
  error.value = ''
  savingProduct.value = true
  try {
    await request('/api/products', {
      method: 'POST',
      body: JSON.stringify(productForm.value),
    })
    productForm.value = { name: '', purchasePrice: '' }
    await loadProducts()
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    savingProduct.value = false
  }
}

async function saveProduct(product) {
  error.value = ''
  try {
    await request(`/api/products/${product.id}`, {
      method: 'PATCH',
      body: JSON.stringify({ name: product.name, purchasePrice: product.purchasePrice }),
    })
    await loadProducts()
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function deleteProduct(product) {
  if (!await confirmDelete(`Produkt „${product.name}“ löschen?`)) return
  error.value = ''
  try {
    await request(`/api/products/${product.id}`, { method: 'DELETE' })
    await loadProducts()
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function saveMarkup() {
  error.value = ''
  savingMarkup.value = true
  try {
    await request('/api/products/markup', {
      method: 'PATCH',
      body: JSON.stringify({ markupPercent: markupPercent.value }),
    })
    await loadProducts()
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    savingMarkup.value = false
  }
}

async function saveProductMarkup(product) {
  error.value = ''
  try {
    await request(`/api/products/${product.id}/markup`, {
      method: 'PATCH',
      body: JSON.stringify({ markupPercent: product.markupPercent }),
    })
    await loadProducts()
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function loadOrders() {
  try {
    const data = await request('/api/orders')
    orderProducts.value = data.products
    savedOrders.value = data.orders
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function loadCashTransactions() {
  try {
    const data = await request('/api/cash')
    cashTransactions.value = data.transactions
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function createCashTransaction() {
  error.value = ''
  savingCash.value = true
  try {
    await request('/api/cash', {
      method: 'POST',
      body: JSON.stringify(cashForm.value),
    })
    cashForm.value = { date: localDateString(), name: '', purpose: '', amount: '', type: 'income' }
    await loadCashTransactions()
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    savingCash.value = false
  }
}

function startEditingCashTransaction(transaction) {
  cashEditForm.value = { ...transaction, amount: transaction.amount.replace('.', ',') }
}

async function saveCashTransaction() {
  if (!cashEditForm.value) return
  error.value = ''
  savingCash.value = true
  try {
    await request(`/api/cash/${cashEditForm.value.id}`, {
      method: 'PATCH',
      body: JSON.stringify(cashEditForm.value),
    })
    cashEditForm.value = null
    await loadCashTransactions()
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    savingCash.value = false
  }
}

async function deleteCashTransaction(transaction) {
  if (!await confirmDelete(`Kassenbuchung „${transaction.name}“ über ${formatCashCurrency(transaction.amount)} löschen?`)) return
  error.value = ''
  try {
    await request(`/api/cash/${transaction.id}`, { method: 'DELETE' })
    if (cashEditForm.value?.id === transaction.id) cashEditForm.value = null
    await loadCashTransactions()
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function loadBalance() {
  try {
    const data = await request('/api/balance')
    balanceCents.value = data.balanceCents
    balanceTransactions.value = data.transactions
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function loadNotices() {
  try {
    const data = await request('/api/notices')
    noticeSections.value = data.sections
    notices.value = data.notices
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function createNotice(section) {
  error.value = ''
  savingNotice.value = true
  try {
    await request('/api/notices', {
      method: 'POST',
      body: JSON.stringify({ sectionId: section.id, body: noticeDrafts.value[section.id] ?? '' }),
    })
    noticeDrafts.value[section.id] = ''
    await loadNotices()
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    savingNotice.value = false
  }
}

function startEditingNotice(notice) {
  editingNoticeBodies.value[notice.id] = notice.body
}

async function saveNotice(notice) {
  error.value = ''
  try {
    await request(`/api/notices/${notice.id}`, {
      method: 'PATCH',
      body: JSON.stringify({ body: editingNoticeBodies.value[notice.id] }),
    })
    delete editingNoticeBodies.value[notice.id]
    await loadNotices()
  } catch (requestError) {
    error.value = requestError.message
  }
}

function cancelEditingNotice(notice) {
  delete editingNoticeBodies.value[notice.id]
}

async function deleteNotice(notice) {
  if (!await confirmDelete('Diese Nachricht löschen?')) return
  error.value = ''
  try {
    await request(`/api/notices/${notice.id}`, { method: 'DELETE' })
    await loadNotices()
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function createNoticeSection() {
  error.value = ''
  savingNoticeSection.value = true
  try {
    await request('/api/notice-sections', {
      method: 'POST',
      body: JSON.stringify({ name: newNoticeSectionName.value }),
    })
    newNoticeSectionName.value = ''
    await loadNotices()
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    savingNoticeSection.value = false
  }
}

function startRenamingNoticeSection(section) {
  editingNoticeSectionId.value = section.id
  editingNoticeSectionName.value = section.name
}

async function saveNoticeSection(section) {
  error.value = ''
  try {
    await request(`/api/notice-sections/${section.id}`, {
      method: 'PATCH',
      body: JSON.stringify({ name: editingNoticeSectionName.value }),
    })
    editingNoticeSectionId.value = null
    await loadNotices()
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function deleteNoticeSection(section) {
  if (!await confirmDelete(`Bereich „${section.name}“ löschen? Er muss dafür leer sein.`)) return
  error.value = ''
  try {
    await request(`/api/notice-sections/${section.id}`, { method: 'DELETE' })
    await loadNotices()
  } catch (requestError) {
    error.value = requestError.message
  }
}

function formatNoticeDate(value) {
  return new Intl.DateTimeFormat('de-DE', { dateStyle: 'medium', timeStyle: 'short' })
    .format(new Date(value.replace(' ', 'T') + 'Z'))
}

function addOrderLine() {
  orderItems.value.push({ key: nextOrderLineKey.value++, productId: '', quantity: 1 })
}

function removeOrderLine(index) {
  if (orderItems.value.length > 1) orderItems.value.splice(index, 1)
}

function orderProduct(productId) {
  return orderProducts.value.find(({ id }) => id === productId)
}

async function submitOrder() {
  error.value = ''
  orderSuccess.value = ''
  submitting.value = true
  try {
    const data = await request('/api/orders', {
      method: 'POST',
      body: JSON.stringify({
        ...orderForm.value,
        items: orderItems.value.map(({ productId, quantity }) => ({
          productId: Number(productId),
          quantity: Number(quantity),
        })),
      }),
    })
    orderSuccess.value = `Bestellung #${data.order.id} wurde gespeichert. Gesamtsumme: ${formatCurrency(data.order.total)}`
    orderForm.value = { customerName: '', customerPhone: '', groupingName: '' }
    orderItems.value = [{ key: nextOrderLineKey.value++, productId: '', quantity: 1 }]
    await loadOrders()
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    submitting.value = false
  }
}

function formatOrderDate(value) {
  return new Intl.DateTimeFormat('de-DE', { dateStyle: 'medium', timeStyle: 'short' })
    .format(new Date(value.replace(' ', 'T')))
}

async function moveRole(index, direction) {
  const reordered = [...roleOrder.value]
  const targetIndex = index + direction
  if (targetIndex < 0 || targetIndex >= reordered.length) return
  const [movingRole] = reordered.splice(index, 1)
  reordered.splice(targetIndex, 0, movingRole)

  error.value = ''
  savingRoleOrder.value = true
  try {
    await request('/api/roles/order', {
      method: 'PATCH',
      body: JSON.stringify({ roleIds: reordered.map(({ id }) => id) }),
    })
    await loadTeam()
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    savingRoleOrder.value = false
  }
}

async function createEmployee() {
  error.value = ''
  savingEmployee.value = true
  try {
    await request('/api/employees', {
      method: 'POST',
      body: JSON.stringify(employeeForm.value),
    })
    employeeForm.value = { name: '', username: '', password: '', roleId: defaultEmployeeRoleId() }
    await loadTeam()
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    savingEmployee.value = false
  }
}

function editRole(role) {
  roleForm.value = { id: role.id, name: role.name, permissions: [...role.permissions] }
}

function resetRoleForm() {
  roleForm.value = { id: '', name: '', permissions: [] }
}

async function saveRole() {
  error.value = ''
  savingRole.value = true
  const isEditing = Boolean(roleForm.value.id)
  try {
    await request(isEditing ? `/api/roles/${encodeURIComponent(roleForm.value.id)}` : '/api/roles', {
      method: isEditing ? 'PATCH' : 'POST',
      body: JSON.stringify({ name: roleForm.value.name, permissions: roleForm.value.permissions }),
    })
    resetRoleForm()
    await loadTeam()
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    savingRole.value = false
  }
}

async function deleteRole(role) {
  if (!await confirmDelete(`Rolle „${role.name}“ löschen?`)) return
  error.value = ''
  try {
    await request(`/api/roles/${encodeURIComponent(role.id)}`, { method: 'DELETE' })
    await loadTeam()
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function saveEmployee(employee) {
  error.value = ''
  try {
    await request(`/api/employees/${employee.id}`, {
      method: 'PATCH',
      body: JSON.stringify({
        name: employee.name,
        roleId: employee.roleId,
        isActive: employee.isActive,
      }),
    })
    await loadTeam()
  } catch (requestError) {
    error.value = requestError.message
  }
}

watch([activeSection, session], ([section, user]) => {
  if (user && section === 'overview') loadNotices()
  if (section === 'team' || section === 'roles') loadTeam()
  if (section === 'inventory') loadWarehouses()
  if (section === 'products') loadProducts()
  if (section === 'orders') loadOrders()
  if (section === 'cash') loadCashTransactions()
  if (section === 'balance') loadBalance()
})

watch(roles, (items) => {
  if (!employeeForm.value.roleId && items.length) employeeForm.value.roleId = defaultEmployeeRoleId()
}, { deep: true })

onMounted(loadSession)
</script>

<template>
  <main v-if="loading" class="loading-screen" aria-live="polite">
    <span class="wordmark">ECHO</span>
    <p>Arbeitsbereich wird geladen …</p>
  </main>

  <main v-else-if="!session" class="login-screen">
    <section class="login-panel" aria-labelledby="login-title">
      <div class="login-brand wordmark" aria-label="ECHO">ECHO</div>

      <div class="login-copy">
        <h1 id="login-title">Anmelden</h1>
      </div>

      <form class="login-form" @submit.prevent="login">
        <label for="username">Benutzername</label>
        <input
          id="username"
          v-model.trim="username"
          autocomplete="username"
          name="username"
          type="text"
          placeholder="Dein Benutzername"
          minlength="3"
          maxlength="32"
          pattern="[A-Za-z0-9][A-Za-z0-9._\x2d]{2,31}"
          required
        />

        <label for="password">Passwort</label>
        <input
          id="password"
          v-model="password"
          autocomplete="current-password"
          name="password"
          type="password"
          placeholder="Dein Passwort"
          required
        />

        <div v-if="error" class="form-error" role="alert">
          <span>{{ error }}</span>
          <button v-if="!csrfToken" class="retry-button" type="button" @click="loadSession">
            Verbindung erneut prüfen
          </button>
        </div>

        <button class="login-button" type="submit" :disabled="submitting || !csrfToken">
          {{ submitting ? 'Anmeldung läuft …' : 'Anmelden' }}
          <span aria-hidden="true">→</span>
        </button>
      </form>

    </section>
  </main>

  <main v-else class="workspace">
    <aside class="sidebar">
      <a class="sidebar-brand wordmark" href="/" aria-label="ECHO Übersicht">ECHO</a>

      <nav class="side-nav" aria-label="Hauptnavigation">
        <div v-for="group in navigationGroups" :key="group.label || 'main'" class="nav-group">
          <h2 v-if="group.label" class="nav-group-label">{{ group.label }}</h2>
          <button
            v-for="section in group.sections"
            :key="section.id"
            class="nav-item"
            :class="{ 'nav-item-active': activeSection === section.id }"
            type="button"
            :aria-current="activeSection === section.id ? 'page' : undefined"
            @click="activeSection = section.id"
          >
            {{ section.label }}
          </button>
        </div>
      </nav>

      <div class="sidebar-bottom">
        <div class="help-card">
          <span class="help-icon">?</span>
          <strong>Fragen?</strong>
          <span>Wende dich an deine Administration.</span>
        </div>
        <button class="profile-button" type="button" @click="logout">
          <span class="avatar">{{ session.name.slice(0, 1).toLocaleUpperCase('de') }}</span>
          <span class="profile-info">
            <strong>{{ session.name }}</strong>
            <span>{{ session.roleName }}</span>
          </span>
          <span class="logout-mark" aria-label="Abmelden">↗</span>
        </button>
      </div>
    </aside>

    <section class="main-panel">
      <header class="topbar">
        <div class="breadcrumb">Arbeitsbereich <span>/</span> {{ currentSection?.label ?? 'Kein Zugriff' }}</div>
        <div class="topbar-right">
          <span class="topbar-date">{{ new Intl.DateTimeFormat('de-DE', { dateStyle: 'long' }).format(new Date()) }}</span>
          <span class="avatar avatar-small">{{ session.name.slice(0, 1).toLocaleUpperCase('de') }}</span>
        </div>
      </header>

      <div class="content">
        <div v-if="error" class="notice-error" role="alert">
          {{ error }}
          <button type="button" aria-label="Meldung schließen" @click="error = ''">×</button>
        </div>

        <template v-if="activeSection === 'overview'">
          <section class="notice-board" aria-label="Nachrichten">
            <div class="notice-board-heading">
              <div>
                <h2>Nachrichten</h2>
                <p>Wichtige Hinweise und Neuigkeiten für alle Mitglieder.</p>
              </div>
            </div>
            <form v-if="canManageNoticeSections" class="notice-section-create" @submit.prevent="createNoticeSection">
              <input
                v-model.trim="newNoticeSectionName"
                aria-label="Name des neuen Nachrichtenbereichs"
                placeholder="Neuen Bereich benennen"
                maxlength="60"
                required
              />
              <button class="secondary-button" type="submit" :disabled="savingNoticeSection">
                {{ savingNoticeSection ? 'Wird erstellt …' : 'Bereich hinzufügen' }}
              </button>
            </form>
            <div class="notice-grid">
              <article
                v-for="section in noticeSections"
                :key="section.id"
                class="notice-card"
              >
                <header class="notice-card-heading">
                  <template v-if="editingNoticeSectionId === section.id">
                    <form class="notice-rename-form" @submit.prevent="saveNoticeSection(section)">
                      <input v-model.trim="editingNoticeSectionName" maxlength="60" required aria-label="Bereichsname" />
                      <button class="text-link" type="submit">Speichern</button>
                      <button class="text-link" type="button" @click="editingNoticeSectionId = null">Abbrechen</button>
                    </form>
                  </template>
                  <template v-else>
                    <h3>{{ section.name }}</h3>
                    <div v-if="canManageNoticeSections" class="notice-section-actions">
                      <button class="text-link" type="button" @click="startRenamingNoticeSection(section)">Umbenennen</button>
                      <button class="text-link role-delete" type="button" @click="deleteNoticeSection(section)">Löschen</button>
                    </div>
                  </template>
                </header>

                <div class="notice-list">
                  <article v-for="notice in notices.filter((item) => item.sectionId === section.id)" :key="notice.id" class="notice-entry">
                    <div class="notice-entry-meta">
                      <strong>{{ notice.authorName }}</strong>
                      <time :datetime="notice.createdAt.replace(' ', 'T') + 'Z'">{{ formatNoticeDate(notice.createdAt) }}</time>
                    </div>
                    <template v-if="Object.hasOwn(editingNoticeBodies, notice.id)">
                      <textarea v-model="editingNoticeBodies[notice.id]" maxlength="1000" rows="4" aria-label="Nachricht bearbeiten"></textarea>
                      <div class="notice-entry-actions">
                        <button class="text-link" type="button" @click="saveNotice(notice)">Speichern</button>
                        <button class="text-link" type="button" @click="cancelEditingNotice(notice)">Abbrechen</button>
                      </div>
                    </template>
                    <template v-else>
                      <p>{{ notice.body }}</p>
                      <div v-if="canManageNoticeSections || notice.authorId === session.id" class="notice-entry-actions">
                        <button class="text-link" type="button" @click="startEditingNotice(notice)">Bearbeiten</button>
                        <button class="text-link role-delete" type="button" @click="deleteNotice(notice)">Löschen</button>
                      </div>
                    </template>
                  </article>
                  <p v-if="!notices.some((item) => item.sectionId === section.id)" class="notice-empty">
                    Noch keine Nachrichten in diesem Bereich.
                  </p>
                </div>

                <form class="notice-compose" @submit.prevent="createNotice(section)">
                  <label :for="`notice-${section.id}`">Nachricht schreiben</label>
                  <textarea
                    :id="`notice-${section.id}`"
                    v-model="noticeDrafts[section.id]"
                    maxlength="1000"
                    rows="3"
                    placeholder="Teile eine Information mit allen …"
                    required
                  ></textarea>
                  <button class="management-button" type="submit" :disabled="savingNotice">
                    {{ savingNotice ? 'Wird veröffentlicht …' : 'Veröffentlichen' }}
                  </button>
                </form>
              </article>
            </div>
          </section>
        </template>

        <template v-else-if="activeSection === 'orders'">
          <div class="page-heading">
            <div>
              <p class="eyebrow">BESTELLUNGSVERWALTUNG</p>
              <h1>Bestellung aufnehmen</h1>
              <p class="page-subtitle">Produkte auswählen, Mengen eintragen und die Gesamtsumme prüfen.</p>
            </div>
          </div>

          <section class="panel-card management-card">
            <div class="panel-heading">
              <div>
                <h2>Kundendaten</h2>
                <p>Diese Angaben werden mit der Bestellung gespeichert.</p>
              </div>
            </div>
            <form class="management-form order-form" @submit.prevent="submitOrder">
              <div class="order-customer-fields">
                <label>
                  Name
                  <input v-model.trim="orderForm.customerName" maxlength="160" autocomplete="name" required />
                </label>
                <label>
                  Telefonnummer
                  <input v-model.trim="orderForm.customerPhone" type="tel" maxlength="40" autocomplete="tel" required />
                </label>
                <label>
                  Gruppierung
                  <input v-model.trim="orderForm.groupingName" maxlength="160" required />
                </label>
              </div>

              <div class="order-lines-heading">
                <div>
                  <h3>Produkte</h3>
                  <p>Der VK-Preis des Produkts wird für die Bestellung festgehalten.</p>
                </div>
                <button class="secondary-button" type="button" :disabled="orderItems.length >= 50" @click="addOrderLine">
                  Produkt hinzufügen
                </button>
              </div>

              <div class="order-lines">
                <div v-for="(item, index) in orderItems" :key="item.key" class="order-line">
                  <label>
                    Produkt
                    <select v-model="item.productId" required>
                      <option value="" disabled>Produkt auswählen</option>
                      <option v-for="product in orderProducts" :key="product.id" :value="product.id">
                        {{ product.name }} · {{ formatCurrency(product.sellingPrice) }}
                      </option>
                    </select>
                  </label>
                  <label>
                    Anzahl
                    <input v-model.number="item.quantity" type="number" min="1" max="9999" step="1" required />
                  </label>
                  <span class="order-line-total">
                    <span>Zwischensumme</span>
                    <strong>{{ formatCurrencyCents(orderProduct(item.productId) ? priceToCents(orderProduct(item.productId).sellingPrice) * Number(item.quantity || 0) : 0) }}</strong>
                  </span>
                  <button
                    class="text-link role-delete order-remove"
                    type="button"
                    :disabled="orderItems.length === 1"
                    :aria-label="`Produktposition ${index + 1} entfernen`"
                    @click="removeOrderLine(index)"
                  >Entfernen</button>
                </div>
                <p v-if="orderProducts.length === 0" class="order-empty-products">
                  Es sind noch keine Produkte vorhanden. Lege zuerst Produkte im Reiter „Produkte“ an.
                </p>
              </div>

              <div class="order-submit-row">
                <strong>Gesamtsumme: {{ formatCurrencyCents(orderTotalCents) }}</strong>
                <button class="management-button" type="submit" :disabled="submitting || orderProducts.length === 0">
                  {{ submitting ? 'Wird gespeichert …' : 'Bestellung aufgeben' }}
                </button>
              </div>
              <p v-if="orderSuccess" class="order-success" role="status">{{ orderSuccess }}</p>
            </form>
          </section>

          <section class="panel-card management-card">
            <div class="panel-heading">
              <div>
                <h2>Gespeicherte Bestellungen</h2>
                <p>Die letzten {{ savedOrders.length }} Bestellungen</p>
              </div>
            </div>
            <div v-if="savedOrders.length" class="saved-orders">
              <article v-for="order in savedOrders" :key="order.id" class="saved-order">
                <div class="saved-order-heading">
                  <div>
                    <strong>Bestellung #{{ order.id }} · {{ order.customerName }}</strong>
                    <span>{{ order.groupingName }} · {{ order.customerPhone }} · {{ formatOrderDate(order.createdAt) }}</span>
                  </div>
                  <strong class="product-selling-price">{{ formatCurrency(order.total) }}</strong>
                </div>
                <ul>
                  <li v-for="(item, index) in order.items" :key="`${order.id}-${index}`">
                    {{ item.productName }} × {{ item.quantity }} · {{ formatCurrency(item.unitPrice) }} je Stück
                    <span>{{ formatCurrency(item.lineTotal) }}</span>
                  </li>
                </ul>
              </article>
            </div>
            <p v-else class="order-empty-products">Noch keine Bestellungen gespeichert.</p>
          </section>
        </template>

        <template v-else-if="activeSection === 'cash' && canManageCash">
          <div class="page-heading">
            <div>
              <p class="eyebrow">KASSENVERWALTUNG</p>
              <h1>Kasse</h1>
              <p class="page-subtitle">Erfasse Einnahmen und Ausgaben. Summen und Kontostand sind hier nicht sichtbar.</p>
            </div>
          </div>

          <section class="panel-card management-card">
            <div class="panel-heading">
              <div>
                <h2>Neue Buchung</h2>
                <p>Datum, Name, Zweck, Betrag und Buchungsart eintragen.</p>
              </div>
            </div>
            <form class="management-form cash-form" @submit.prevent="createCashTransaction">
              <label>
                Datum
                <input v-model="cashForm.date" type="date" required />
              </label>
              <label>
                Name
                <input v-model.trim="cashForm.name" maxlength="160" required />
              </label>
              <label>
                Zweck
                <input v-model.trim="cashForm.purpose" maxlength="160" required />
              </label>
              <label>
                Betrag in Euro
                <input
                  v-model="cashForm.amount"
                  type="text"
                  inputmode="decimal"
                  maxlength="12"
                  pattern="[0-9]{1,9}([.,][0-9]{1,2})?"
                  placeholder="0,00"
                  required
                />
              </label>
              <label>
                Buchungsart
                <select v-model="cashForm.type" required>
                  <option value="income">Einnahme</option>
                  <option value="expense">Ausgabe</option>
                </select>
              </label>
              <button class="management-button" type="submit" :disabled="savingCash">
                {{ savingCash ? 'Wird gespeichert …' : 'Buchung speichern' }}
              </button>
            </form>
          </section>

          <section v-if="cashEditForm" class="panel-card management-card">
            <div class="panel-heading">
              <div>
                <h2>Buchung bearbeiten</h2>
                <p>Änderungen wirken sich auf den Kontostand aus.</p>
              </div>
            </div>
            <form class="management-form cash-form" @submit.prevent="saveCashTransaction">
              <label>
                Datum
                <input v-model="cashEditForm.date" type="date" required />
              </label>
              <label>
                Name
                <input v-model.trim="cashEditForm.name" maxlength="160" required />
              </label>
              <label>
                Zweck
                <input v-model.trim="cashEditForm.purpose" maxlength="160" required />
              </label>
              <label>
                Betrag in Euro
                <input
                  v-model="cashEditForm.amount"
                  type="text"
                  inputmode="decimal"
                  maxlength="12"
                  pattern="[0-9]{1,9}([.,][0-9]{1,2})?"
                  required
                />
              </label>
              <label>
                Buchungsart
                <select v-model="cashEditForm.type" required>
                  <option value="income">Einnahme</option>
                  <option value="expense">Ausgabe</option>
                </select>
              </label>
              <div class="form-actions">
                <button class="management-button" type="submit" :disabled="savingCash">
                  {{ savingCash ? 'Wird gespeichert …' : 'Änderungen speichern' }}
                </button>
                <button class="secondary-button" type="button" @click="cashEditForm = null">Abbrechen</button>
              </div>
            </form>
          </section>

          <section class="panel-card management-card">
            <div class="panel-heading">
              <div>
                <h2>Einzelne Buchungen</h2>
                <p>{{ cashTransactions.length }} {{ cashTransactions.length === 1 ? 'Buchung' : 'Buchungen' }} · keine Summenansicht</p>
              </div>
            </div>
            <div class="cash-entry-list">
              <article v-for="transaction in cashTransactions" :key="transaction.id" class="cash-entry">
                <div class="cash-entry-details">
                  <strong>{{ transaction.name }}</strong>
                  <span>Zweck: {{ transaction.purpose || '—' }}</span>
                  <span>{{ transaction.date }}</span>
                </div>
                <span class="cash-entry-type" :class="`cash-type-${transaction.type}`">
                  {{ transaction.type === 'income' ? 'Einnahme' : 'Ausgabe' }}
                </span>
                <strong class="cash-entry-amount">{{ formatCashCurrency(transaction.amount) }}</strong>
                <div class="cash-entry-actions">
                  <button class="text-link" type="button" @click="startEditingCashTransaction(transaction)">Bearbeiten</button>
                  <button class="text-link role-delete" type="button" @click="deleteCashTransaction(transaction)">Löschen</button>
                </div>
              </article>
              <p v-if="cashTransactions.length === 0" class="cash-empty">Noch keine Kassenbuchungen vorhanden.</p>
            </div>
          </section>
        </template>

        <template v-else-if="activeSection === 'balance' && canViewBalance">
          <div class="page-heading">
            <div>
              <p class="eyebrow">FINANZÜBERSICHT</p>
              <h1>Kontostand</h1>
              <p class="page-subtitle">Aktueller Stand und vollständiger Verlauf aller Einnahmen und Ausgaben.</p>
            </div>
          </div>

          <section class="balance-card" aria-label="Aktueller Kontostand">
            <span>Aktueller Kontostand</span>
            <strong>{{ formatCashCurrencyCents(balanceCents) }}</strong>
          </section>

          <section class="panel-card management-card">
            <div class="panel-heading">
              <div>
                <h2>Buchungsverlauf</h2>
                <p>{{ balanceTransactions.length }} {{ balanceTransactions.length === 1 ? 'Buchung' : 'Buchungen' }}</p>
              </div>
            </div>
            <div class="table-scroll">
              <table class="management-table balance-table">
                <thead>
                  <tr><th>DATUM</th><th>NAME</th><th>ZWECK</th><th>ART</th><th>BETRAG</th><th>KONTOSTAND NACH BUCHUNG</th></tr>
                </thead>
                <tbody>
                  <tr v-for="transaction in balanceTransactions" :key="transaction.id">
                    <td>{{ transaction.date }}</td>
                    <td class="product-name">{{ transaction.name }}</td>
                    <td>{{ transaction.purpose || '—' }}</td>
                    <td>
                      <span class="cash-entry-type" :class="`cash-type-${transaction.type}`">
                        {{ transaction.type === 'income' ? 'Einnahme' : 'Ausgabe' }}
                      </span>
                    </td>
                    <td :class="transaction.type === 'income' ? 'balance-income' : 'balance-expense'">
                      {{ transaction.type === 'income' ? '+' : '−' }}{{ formatCashCurrency(transaction.amount) }}
                    </td>
                    <td class="product-selling-price">{{ formatCashCurrencyCents(transaction.balanceCents) }}</td>
                  </tr>
                  <tr v-if="balanceTransactions.length === 0">
                    <td class="empty-result" colspan="6">Noch keine Kassenbuchungen vorhanden.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </template>

        <template v-else-if="activeSection === 'products'">
          <div class="page-heading">
            <div>
              <p class="eyebrow">PRODUKTVERWALTUNG</p>
              <h1>Produkte</h1>
              <p class="page-subtitle">Pflege Einkaufspreise und berechne daraus automatisch den Verkaufspreis.</p>
            </div>
            <span class="demo-tag">STANDARD {{ formatPercent(markupPercent) }} %</span>
          </div>

          <section v-if="canManageProductMarkup" class="panel-card management-card">
            <div class="panel-heading">
              <div>
                <h2>Standard-Aufschlag für neue Produkte</h2>
                <p>Neue Produkte übernehmen diesen Standard. Der Aufschlag bestehender Produkte wird separat festgelegt.</p>
              </div>
            </div>
            <form class="management-form markup-form" @submit.prevent="saveMarkup">
              <label>
                Standard-Aufschlag in Prozent
                <input
                  v-model="markupPercent"
                  type="text"
                  inputmode="decimal"
                  maxlength="7"
                  pattern="[0-9]{1,4}([.,][0-9]{1,2})?"
                  aria-label="Produkt-Aufschlag in Prozent"
                  required
                />
              </label>
              <button class="management-button" type="submit" :disabled="savingMarkup">
                {{ savingMarkup ? 'Wird gespeichert …' : 'Aufschlag speichern' }}
              </button>
            </form>
          </section>

          <section v-if="canManageProducts" class="panel-card management-card">
            <div class="panel-heading">
              <div>
                <h2>Produkt hinzufügen</h2>
                <p>Der VK wird nach dem Speichern mit dem Standard-Aufschlag berechnet.</p>
              </div>
            </div>
            <form class="management-form product-create-form" @submit.prevent="createProduct">
              <label>
                Produktname
                <input v-model.trim="productForm.name" maxlength="160" required />
              </label>
              <label>
                Einkaufspreis (EK)
                <input
                  v-model="productForm.purchasePrice"
                  type="text"
                  inputmode="decimal"
                  maxlength="12"
                  pattern="[0-9]{1,9}([.,][0-9]{1,2})?"
                  placeholder="0,00"
                  required
                />
              </label>
              <button class="management-button" type="submit" :disabled="savingProduct">
                {{ savingProduct ? 'Wird angelegt …' : 'Produkt hinzufügen' }}
              </button>
            </form>
          </section>

          <section class="panel-card management-card">
            <div class="panel-heading">
              <div>
                <h2>Produktliste</h2>
                <p>{{ products.length }} {{ products.length === 1 ? 'Produkt' : 'Produkte' }} · Preise in Euro</p>
              </div>
            </div>
            <div class="table-scroll">
              <table class="management-table product-table">
                <thead>
                  <tr>
                    <th>PRODUKT</th><th>EINKAUFSPREIS (EK)</th>
                    <th>AUFSCHLAG PRO PRODUKT</th><th>VERKAUFSPREIS (VK)</th>
                    <th v-if="canManageProducts"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="product in products" :key="product.id">
                    <td>
                      <input
                        v-if="canManageProducts"
                        v-model.trim="product.name"
                        class="table-input"
                        maxlength="160"
                        aria-label="Produktname"
                        required
                      />
                      <span v-else class="product-name">{{ product.name }}</span>
                    </td>
                    <td>
                      <input
                        v-if="canManageProducts"
                        v-model="product.purchasePrice"
                        class="table-input product-price-input"
                        type="text"
                        inputmode="decimal"
                        maxlength="12"
                        pattern="[0-9]{1,9}([.,][0-9]{1,2})?"
                        aria-label="Einkaufspreis"
                        required
                      />
                      <span v-else>{{ formatCurrency(product.purchasePrice) }}</span>
                    </td>
                    <td>
                      <label v-if="canManageProductMarkup" class="product-markup-field">
                        <input
                          v-model="product.markupPercent"
                          class="table-input product-price-input"
                          type="text"
                          inputmode="decimal"
                          maxlength="7"
                          pattern="[0-9]{1,4}([.,][0-9]{1,2})?"
                          aria-label="Produkt-Aufschlag in Prozent"
                          required
                        />
                        <button class="text-link" type="button" @click="saveProductMarkup(product)">Speichern</button>
                      </label>
                      <span v-else>{{ formatPercent(product.markupPercent) }} %</span>
                    </td>
                    <td class="product-selling-price">{{ formatCurrency(product.sellingPrice) }}</td>
                    <td v-if="canManageProducts" class="product-actions">
                      <button class="text-link" type="button" @click="saveProduct(product)">Speichern</button>
                      <button class="text-link role-delete" type="button" @click="deleteProduct(product)">Löschen</button>
                    </td>
                  </tr>
                  <tr v-if="products.length === 0">
                    <td class="empty-result" :colspan="4 + Number(canManageProducts)">Es wurden noch keine Produkte eingetragen.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </template>

        <template v-else-if="activeSection === 'inventory'">
          <div class="page-heading">
            <div>
              <p class="eyebrow">LAGERÜBERSICHT</p>
              <h1>Lager</h1>
              <p class="page-subtitle">Schließfachnummer, PIN und Inhalt je Mitglied und Lager.</p>
            </div>
            <span class="demo-tag"><span></span> {{ canManageWarehouses ? 'LAGER VERWALTEN' : 'LAGERÜBERSICHT' }}</span>
          </div>

          <section v-if="canManageWarehouses" class="panel-card management-card">
            <div class="panel-heading">
              <div>
                <h2>Lager hinzufügen</h2>
                <p>Jedes angelegte Lager erhält eine eigene Tabellenspalte.</p>
              </div>
            </div>
            <form class="management-form warehouse-create-form" @submit.prevent="createWarehouse">
              <label>
                Lagername
                <input v-model.trim="warehouseName" maxlength="100" required />
              </label>
              <button class="management-button" type="submit" :disabled="savingWarehouse">
                {{ savingWarehouse ? 'Wird hinzugefügt …' : 'Lager hinzufügen' }}
              </button>
            </form>
          </section>

          <section class="panel-card warehouse-table-card">
            <div v-if="warehouses.length" class="table-scroll">
              <table class="warehouse-table">
                <thead>
                  <tr>
                    <th class="member-column">MITGLIED</th>
                    <th v-for="warehouse in warehouses" :key="warehouse.id" class="warehouse-column">
                      <div v-if="canManageWarehouses" class="warehouse-heading">
                        <input
                          v-model.trim="warehouse.name"
                          :aria-label="`Name des Lagers ${warehouse.name}`"
                          maxlength="100"
                          @change="renameWarehouse(warehouse)"
                        />
                        <button class="text-link role-delete" type="button" @click="deleteWarehouse(warehouse)">Löschen</button>
                      </div>
                      <span v-else>{{ warehouse.name }}</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="member in employees" :key="member.id">
                    <th class="member-column" scope="row">
                      <strong>{{ member.name }}</strong>
                      <span>{{ member.roleName }}</span>
                    </th>
                    <td v-for="warehouse in warehouses" :key="warehouse.id">
                      <div class="warehouse-fields">
                        <label>
                          Schließfachnummer
                          <input
                            v-if="canEditWarehouseEntries"
                            v-model="warehouse.entries[member.id].lockerNumber"
                            :aria-label="`${warehouse.name}, ${member.name}, Schließfachnummer`"
                            maxlength="200"
                            @blur="saveWarehouseEntry(warehouse, member)"
                          />
                          <span v-else>{{ warehouse.entries[member.id]?.lockerNumber || '—' }}</span>
                        </label>
                        <label>
                          PIN
                          <input
                            v-if="canEditWarehouseEntries"
                            v-model="warehouse.entries[member.id].pin"
                            :aria-label="`${warehouse.name}, ${member.name}, PIN`"
                            type="password"
                            maxlength="200"
                            autocomplete="new-password"
                            @blur="saveWarehouseEntry(warehouse, member)"
                          />
                          <span v-else>Verborgen</span>
                        </label>
                        <label>
                          Inhalt
                          <textarea
                            v-if="canEditWarehouseEntries"
                            v-model="warehouse.entries[member.id].contents"
                            :aria-label="`${warehouse.name}, ${member.name}, Inhalt`"
                            maxlength="4000"
                            rows="2"
                            placeholder="Inhalt …"
                            @blur="saveWarehouseEntry(warehouse, member)"
                          ></textarea>
                          <span v-else>{{ warehouse.entries[member.id]?.contents || '—' }}</span>
                        </label>
                      </div>
                    </td>
                  </tr>
                  <tr v-if="employees.length === 0">
                    <td class="empty-result" :colspan="warehouses.length + 1">Noch keine aktiven Mitglieder vorhanden.</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-else class="warehouse-empty">
              <p>{{ canManageWarehouses ? 'Lege oben dein erstes Lager an, um die Tabelle aufzubauen.' : 'Es wurden noch keine Lager angelegt.' }}</p>
            </div>
          </section>
          <p class="demo-note">Die Zeilen sind nach Rollenrang und anschließend alphabetisch nach Mitgliedsnamen sortiert.</p>
        </template>

        <template v-else-if="activeSection === 'team'">
          <div class="page-heading">
            <div>
              <p class="eyebrow">TEAMVERWALTUNG</p>
              <h1>Mitglieder</h1>
              <p class="page-subtitle">Verwalte Mitgliederkonten und weise passende Rollen zu.</p>
            </div>
          </div>

          <section v-if="canAddEmployees" class="panel-card management-card">
            <div class="panel-heading">
              <div>
                <h2>Mitglied hinzufügen</h2>
                <p>Das Passwort wird sicher als Hash gespeichert.</p>
              </div>
            </div>
            <form class="management-form employee-create-form" @submit.prevent="createEmployee">
              <label>
                Anzeigename
                <input v-model.trim="employeeForm.name" maxlength="160" autocomplete="name" required />
              </label>
              <label>
                Benutzername
                <input
                  v-model.trim="employeeForm.username"
                  autocomplete="off"
                  minlength="3"
                  maxlength="32"
                  pattern="[A-Za-z0-9][A-Za-z0-9._\x2d]{2,31}"
                  required
                />
              </label>
              <label>
                Initiales Passwort
                <input v-model="employeeForm.password" type="password" maxlength="1024" autocomplete="new-password" required />
              </label>
              <label>
                Rolle
                <select v-model="employeeForm.roleId" required>
                  <option v-for="role in assignableRoles" :key="role.id" :value="role.id">{{ role.name }}</option>
                </select>
              </label>
              <button class="management-button" type="submit" :disabled="savingEmployee || !employeeForm.roleId">
                {{ savingEmployee ? 'Wird angelegt …' : 'Mitglied anlegen' }}
              </button>
            </form>
          </section>

          <section v-if="canViewEmployees" class="panel-card management-card">
            <div class="panel-heading">
              <div>
                <h2>Team</h2>
                <p>{{ employees.length }} {{ employees.length === 1 ? 'Mitglied' : 'Mitglieder' }}</p>
              </div>
            </div>
            <div class="table-scroll">
              <table class="management-table">
                <thead>
                  <tr><th>NAME</th><th>BENUTZERNAME</th><th>ROLLE</th><th>STATUS</th><th></th></tr>
                </thead>
                <tbody>
                  <tr v-for="employee in employees" :key="employee.id">
                    <td>
                      <input
                        v-if="canManageEmployees && employee.id !== session.id"
                        v-model.trim="employee.name"
                        class="table-input"
                        maxlength="160"
                        aria-label="Anzeigename"
                        required
                      />
                      <span v-else class="product-name">{{ employee.name }}</span>
                    </td>
                    <td>{{ employee.username }}</td>
                    <td>
                      <select v-if="canManageEmployees && employee.id !== session.id" v-model="employee.roleId" class="table-select" aria-label="Rolle">
                        <option
                          v-for="role in roles"
                          :key="role.id"
                          :value="role.id"
                          :disabled="!role.assignable && role.id !== employee.roleId"
                        >{{ role.name }}</option>
                      </select>
                      <span v-else>{{ employee.roleName }}</span>
                    </td>
                    <td>
                      <label v-if="canManageEmployees && employee.id !== session.id" class="active-toggle">
                        <input
                          v-model="employee.isActive"
                          type="checkbox"
                          :disabled="employee.id === session.id"
                          :aria-label="`${employee.username} aktiv`"
                        />
                        {{ employee.isActive ? 'Aktiv' : 'Deaktiviert' }}
                      </label>
                      <span v-else>{{ employee.isActive ? 'Aktiv' : 'Deaktiviert' }}</span>
                    </td>
                    <td>
                      <button
                        v-if="canManageEmployees && employee.id !== session.id"
                        class="text-link"
                        type="button"
                        @click="saveEmployee(employee)"
                      >Speichern</button>
                    </td>
                  </tr>
                  <tr v-if="employees.length === 0">
                    <td class="empty-result" colspan="5">Noch keine Mitglieder angelegt.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

        </template>

        <template v-else-if="activeSection === 'roles' && (canManageRoles || canManageRoleOrdering)">
          <div class="page-heading">
            <div>
              <p class="eyebrow">ZUGRIFFSVERWALTUNG</p>
              <h1>Rollen &amp; Rechte</h1>
              <p class="page-subtitle">Lege fest, welche Bereiche und Aktionen den einzelnen Rollen freigegeben sind.</p>
            </div>
          </div>

          <section v-if="canManageRoleOrdering" class="panel-card management-card">
            <div class="panel-heading">
              <div>
                <h2>Rangfolge der Mitglieder</h2>
                <p>Die Rollenreihenfolge bestimmt, in welcher Reihenfolge Mitglieder im Lagersystem erscheinen.</p>
              </div>
            </div>
            <ol class="rank-order-list">
              <li v-for="(role, index) in roleOrder" :key="role.id">
                <span><strong>{{ index + 1 }}.</strong> {{ role.name }}</span>
                <span class="rank-actions">
                  <button
                    class="secondary-button rank-button"
                    type="button"
                    :disabled="savingRoleOrder || index === 0"
                    :aria-label="`${role.name} nach oben`"
                    @click="moveRole(index, -1)"
                  >↑</button>
                  <button
                    class="secondary-button rank-button"
                    type="button"
                    :disabled="savingRoleOrder || index === roleOrder.length - 1"
                    :aria-label="`${role.name} nach unten`"
                    @click="moveRole(index, 1)"
                  >↓</button>
                </span>
              </li>
            </ol>
          </section>

          <section v-if="canManageRoles" class="panel-card management-card">
            <div class="panel-heading">
              <div>
                <h2>Rollen und Berechtigungen</h2>
                <p>Stelle Rechte pro Rolle individuell zusammen. Die Administrator-Systemrolle bleibt geschützt.</p>
              </div>
            </div>
            <div class="role-management">
              <div class="role-list">
                <article v-for="role in roles" :key="role.id" class="role-item">
                  <div>
                    <strong>{{ role.name }}</strong>
                    <span>{{ role.permissions.length }} Berechtigungen{{ role.isSystem ? ' · Systemrolle' : '' }}</span>
                  </div>
                  <div v-if="!role.isSystem" class="role-actions">
                    <button class="text-link" type="button" @click="editRole(role)">Bearbeiten</button>
                    <button class="text-link role-delete" type="button" @click="deleteRole(role)">Löschen</button>
                  </div>
                </article>
              </div>

              <form class="management-form role-form" @submit.prevent="saveRole">
                <h3>{{ roleForm.id ? 'Rolle bearbeiten' : 'Neue Rolle erstellen' }}</h3>
                <label>
                  Rollenname
                  <input v-model.trim="roleForm.name" minlength="2" maxlength="60" required />
                </label>
                <fieldset class="permission-list">
                  <legend>Berechtigungen</legend>
                  <label v-for="permission in permissionOptions" :key="permission.id">
                    <input v-model="roleForm.permissions" type="checkbox" :value="permission.id" />
                    {{ permission.label }}
                  </label>
                </fieldset>
                <div class="form-actions">
                  <button class="management-button" type="submit" :disabled="savingRole">
                    {{ savingRole ? 'Wird gespeichert …' : roleForm.id ? 'Änderungen speichern' : 'Rolle erstellen' }}
                  </button>
                  <button v-if="roleForm.id" class="secondary-button" type="button" @click="resetRoleForm">Abbrechen</button>
                </div>
              </form>
            </div>
          </section>
        </template>

        <section v-else-if="currentSection" class="module-placeholder panel-card">
          <span class="placeholder-mark">{{ currentSection.mark }}</span>
          <p class="eyebrow">ARBEITSBEREICH</p>
          <h1>{{ currentSection.label }}</h1>
          <p>Dieser Bereich ist vorbereitet und kann als nächstes mit den passenden Funktionen und Daten ausgebaut werden.</p>
          <span class="demo-tag"><span></span> VORSCHAU</span>
        </section>

        <section v-else class="module-placeholder panel-card">
          <p class="eyebrow">ZUGRIFF BESCHRÄNKT</p>
          <h1>Keine Bereiche freigegeben</h1>
          <p>Bitte wende dich an eine Administratorin oder einen Administrator, um eine passende Rolle zu erhalten.</p>
        </section>
      </div>
    </section>
  </main>

  <div
    v-if="deleteConfirmation"
    class="confirmation-overlay"
    role="presentation"
    @click.self="resolveDeleteConfirmation(false)"
    @keydown.esc.prevent.stop="resolveDeleteConfirmation(false)"
  >
    <section
      class="confirmation-dialog"
      role="alertdialog"
      aria-modal="true"
      aria-labelledby="delete-confirmation-title"
      aria-describedby="delete-confirmation-message"
    >
      <span class="confirmation-icon" aria-hidden="true">!</span>
      <h2 id="delete-confirmation-title">Löschen bestätigen</h2>
      <p id="delete-confirmation-message">{{ deleteConfirmation }}</p>
      <div class="confirmation-actions">
        <button class="secondary-button" type="button" autofocus @click="resolveDeleteConfirmation(false)">Abbrechen</button>
        <button class="management-button confirmation-delete" type="button" @click="resolveDeleteConfirmation(true)">Löschen</button>
      </div>
    </section>
  </div>
</template>

<style scoped>
.loading-screen {
  display: grid;
  min-height: 100vh;
  align-content: center;
  justify-items: center;
  gap: 14px;
  color: #728076;
  font-size: 0.9rem;
}

.loading-screen p { margin: 0; }

.wordmark {
  display: inline-flex;
  width: fit-content;
  align-items: baseline;
  color: #ededed;
  font-family: "Segoe Script", "Brush Script MT", "URW Chancery L", cursive;
  font-size: 1.8rem;
  font-style: italic;
  font-weight: 600;
  letter-spacing: -0.055em;
  line-height: 1.15;
  text-decoration: none;
}

.login-screen {
  display: grid;
  min-height: 100vh;
  padding: 24px;
  place-items: center;
  background: #f7f8f5;
}

.login-panel {
  display: flex;
  width: min(100%, 420px);
  flex-direction: column;
  padding: clamp(28px, 7vw, 42px);
  border: 1px solid #e8ece6;
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 18px 55px rgb(32 51 41 / 8%);
}

.login-copy { margin-top: 38px; }
.eyebrow {
  margin: 0 0 13px;
  color: #839087;
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.12em;
}

.login-copy h1,
.page-heading h1 {
  margin: 0;
  color: #1c2a22;
  font-size: clamp(2rem, 4vw, 2.8rem);
  font-weight: 650;
  letter-spacing: -0.065em;
}

.login-copy h1 { font-size: 2rem; }

.login-form {
  display: grid;
  margin-top: 24px;
  gap: 10px;
}

.login-form label {
  margin-top: 8px;
  color: #435248;
  font-size: 0.81rem;
  font-weight: 700;
}

.login-form input {
  width: 100%;
  height: 50px;
  padding: 0 15px;
  border: 1px solid #e2e8e3;
  border-radius: 9px;
  outline: 0;
  background: #fff;
  color: #24352a;
  font: inherit;
  font-size: 0.88rem;
}

.login-form input::placeholder { color: #aab2ac; }
.login-form input:focus { border-color: #728a5a; box-shadow: 0 0 0 3px rgb(215 242 117 / 28%); }

.login-button {
  display: flex;
  height: 50px;
  align-items: center;
  justify-content: space-between;
  margin-top: 16px;
  padding: 0 17px;
  border: 0;
  border-radius: 9px;
  background: #203329;
  color: #fff;
  cursor: pointer;
  font: inherit;
  font-size: 0.9rem;
  font-weight: 700;
}

.login-button span { color: #d7f275; font-size: 1.2rem; }
.login-button:hover { background: #2e4737; }
.login-button:disabled { cursor: wait; opacity: 0.7; }

.form-error {
  display: grid;
  gap: 8px;
  margin: 5px 0 0;
  color: #b33d35;
  font-size: 0.84rem;
  line-height: 1.5;
}

.retry-button {
  width: fit-content;
  padding: 0;
  border: 0;
  background: transparent;
  color: #8c342d;
  cursor: pointer;
  font: inherit;
  font-weight: 700;
  text-decoration: underline;
  text-underline-offset: 2px;
}

.workspace {
  display: grid;
  min-height: 100vh;
  grid-template-columns: 252px minmax(0, 1fr);
  background: #080808;
}

.sidebar {
  position: sticky;
  top: 0;
  display: flex;
  height: 100vh;
  flex-direction: column;
  padding: 27px 17px 15px;
  border-right: 1px solid #e9ede8;
  background: #fff;
}

.sidebar-brand { margin: 0 0 52px 6px; font-size: 1.65rem; }
.side-nav { display: grid; gap: 18px; }
.nav-group { display: grid; gap: 5px; }
.nav-group + .nav-group { padding-top: 13px; border-top: 1px solid rgb(255 255 255 / 20%); }
.nav-group-label {
  margin: 0 0 5px;
  padding: 0 11px;
  color: #fff;
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.nav-item {
  display: flex;
  width: 100%;
  height: 43px;
  align-items: center;
  gap: 12px;
  padding: 0 11px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: #718076;
  cursor: pointer;
  text-align: left;
  font: inherit;
  font-size: 0.84rem;
  font-weight: 550;
}

.nav-item:hover { background: #f6f8f3; color: #354a3b; }
.nav-item-active { background: #eff4e8; color: #334937; font-weight: 700; }
.sidebar-bottom { margin-top: auto; }
.help-card { display: grid; grid-template-columns: 28px 1fr; align-items: center; gap: 4px 9px; margin: 0 2px 15px; padding: 13px 11px; border: 1px solid #edf0ea; border-radius: 9px; }
.help-icon { display: grid; width: 25px; height: 25px; grid-row: span 2; place-items: center; border-radius: 50%; background: #f1f4eb; color: #697a5d; font-size: 0.77rem; font-weight: 700; }
.help-card strong { color: #47564b; font-size: 0.75rem; }
.help-card > span:last-child { color: #969f98; font-size: 0.65rem; line-height: 1.45; }

.profile-button {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 10px;
  padding: 12px 4px 4px;
  border: 0;
  border-top: 1px solid #edf0ea;
  background: transparent;
  cursor: pointer;
  text-align: left;
}

.avatar { display: grid; width: 34px; height: 34px; flex: 0 0 auto; place-items: center; border-radius: 50%; background: #e7eee2; color: #526a50; font-size: 0.83rem; font-weight: 800; }
.profile-info { display: grid; flex: 1; gap: 3px; }
.profile-info strong { overflow: hidden; color: #34443a; font-size: 0.77rem; font-weight: 700; text-overflow: ellipsis; white-space: nowrap; }
.profile-info > span { color: #96a098; font-size: 0.68rem; }
.logout-mark { color: #829087; font-size: 1rem; }

.main-panel { min-width: 0; background: #080808; }
.topbar { display: flex; height: 65px; align-items: center; justify-content: space-between; padding: 0 clamp(22px, 4vw, 52px); border-bottom: 1px solid #e9ede8; background: #fff; }
.breadcrumb { color: #98a19a; font-size: 0.76rem; }
.breadcrumb span { padding: 0 7px; color: #c5ccc6; }
.topbar-right { display: flex; align-items: center; gap: 16px; }
.topbar-date { color: #8a958d; font-size: 0.74rem; }
.avatar-small { width: 31px; height: 31px; font-size: 0.75rem; }

.content { width: min(100%, 1240px); margin: 0 auto; padding: 38px clamp(22px, 4vw, 52px) 42px; }
.page-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 24px; margin-bottom: 27px; }
.page-heading .eyebrow { margin-bottom: 9px; }
.page-heading h1 { font-size: clamp(1.8rem, 3vw, 2.35rem); }
.page-subtitle { margin: 8px 0 0; color: #89948c; font-size: 0.84rem; }
.wave { display: inline-block; color: #b2cf64; font-size: 1.1rem; vertical-align: 8px; }
.demo-tag { display: inline-flex; align-items: center; gap: 7px; padding: 8px 10px; border: 1px solid #e5eadf; border-radius: 7px; background: #fff; color: #869181; font-size: 0.59rem; font-weight: 800; letter-spacing: 0.09em; white-space: nowrap; }
.demo-tag > span { width: 6px; height: 6px; border-radius: 50%; background: #a8bf6e; }

.panel-card { overflow: hidden; border: 1px solid #e8ece6; border-radius: 10px; background: #fff; }
.panel-heading { display: flex; min-height: 83px; align-items: center; justify-content: space-between; gap: 16px; padding: 17px 20px; }
.panel-heading h2 { display: flex; align-items: center; gap: 10px; margin: 0; color: #35463a; font-size: 0.9rem; font-weight: 700; }
.panel-heading p { margin: 6px 0 0; color: #9aa39c; font-size: 0.7rem; }
.text-link { border: 0; background: none; color: #60784c; cursor: pointer; font: inherit; font-size: 0.73rem; font-weight: 700; }
.text-link span { padding-left: 4px; }
.table-scroll { width: 100%; overflow-x: auto; }
table { width: 100%; border-collapse: collapse; text-align: left; white-space: nowrap; }
thead { background: #fafbf9; }
th { height: 37px; padding: 0 17px; color: #9ba49d; font-size: 0.58rem; font-weight: 800; letter-spacing: 0.075em; }
td { height: 62px; padding: 8px 17px; border-top: 1px solid #f0f2ee; color: #7d8980; font-size: 0.71rem; }
.product-name { color: #47574b; font-weight: 700; }
.product-name span { display: block; margin-top: 4px; color: #a0a9a2; font-size: 0.64rem; font-weight: 400; }
.stock-status { display: inline-flex; align-items: center; gap: 6px; font-size: 0.66rem; }
.stock-status > span { width: 6px; height: 6px; border-radius: 50%; }
.status-good { color: #638051; }
.status-good > span { background: #8bab69; }
.status-low { color: #bd7c39; }
.status-low > span { background: #d39c5e; }
.inventory-panel .panel-heading { min-height: 91px; }
.result-count { display: grid; min-width: 22px; height: 22px; place-items: center; border-radius: 12px; background: #f1f4eb; color: #738365; font-size: 0.65rem; }
.search-field { display: flex; width: min(100%, 235px); height: 37px; align-items: center; gap: 8px; padding: 0 10px; border: 1px solid #e9ede7; border-radius: 7px; color: #869188; }
.search-field > span { font-size: 1.15rem; }
.search-field input { width: 100%; border: 0; outline: 0; background: transparent; color: #48574c; font: inherit; font-size: 0.73rem; }
.search-field input::placeholder { color: #a4ada6; }
.empty-result { height: 80px; color: #919b93; text-align: center; }

.module-placeholder { display: grid; min-height: 360px; align-content: center; justify-items: center; padding: 32px; text-align: center; }
.placeholder-mark { display: grid; width: 50px; height: 50px; margin-bottom: 24px; place-items: center; border-radius: 15px; background: #eff4e8; color: #63794c; font-size: 1.3rem; font-weight: 800; }
.module-placeholder .eyebrow { margin-bottom: 8px; }
.module-placeholder h1 { margin: 0; color: #26382c; font-size: 1.8rem; letter-spacing: -0.05em; }
.module-placeholder > p:not(.eyebrow) { max-width: 440px; margin: 10px 0 20px; color: #909a92; font-size: 0.85rem; line-height: 1.6; }
.notice-error { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 18px; padding: 12px 14px; border: 1px solid #f0d7d3; border-radius: 8px; background: #fff8f6; color: #a8473e; font-size: 0.83rem; }
.notice-error button { border: 0; background: transparent; color: inherit; cursor: pointer; font-size: 1.15rem; }
.confirmation-overlay { position: fixed; inset: 0; z-index: 20; display: grid; padding: 20px; place-items: center; background: rgb(0 0 0 / 68%); }
.confirmation-dialog { width: min(100%, 420px); padding: 24px; border: 1px solid #414141; border-radius: 12px; background: #171717; box-shadow: 0 24px 80px rgb(0 0 0 / 55%); }
.confirmation-icon { display: grid; width: 38px; height: 38px; place-items: center; border: 1px solid #66443e; border-radius: 50%; background: #392622; color: #f0a69e; font-weight: 800; }
.confirmation-dialog h2 { margin: 17px 0 8px; color: #f0f0f0; font-size: 1.05rem; }
.confirmation-dialog p { margin: 0; color: #bcbcbc; font-size: 0.82rem; line-height: 1.55; }
.confirmation-actions { display: flex; justify-content: flex-end; gap: 9px; margin-top: 24px; }
.confirmation-delete { background: #a8473e; color: #fff; }
.confirmation-delete:hover { background: #bd574d; }

@media (max-width: 900px) {
  .workspace { grid-template-columns: 218px minmax(0, 1fr); }
}

@media (max-width: 700px) {
  .workspace { display: block; }
  .sidebar { position: static; width: 100%; height: auto; padding: 13px 15px 10px; border-right: 0; border-bottom: 1px solid #e9ede8; }
  .sidebar-brand { margin: 0 0 13px 2px; }
  .sidebar-bottom { display: none; }
  .side-nav { display: flex; align-items: stretch; gap: 10px; overflow-x: auto; }
  .nav-group { display: flex; align-items: center; gap: 5px; flex: 0 0 auto; }
  .nav-group + .nav-group { padding-top: 0; padding-left: 10px; border-top: 0; border-left: 1px solid rgb(255 255 255 / 28%); }
  .nav-group-label { margin: 0 3px 0 0; padding: 0; font-size: 0.58rem; letter-spacing: 0.06em; }
  .nav-item { width: auto; height: 37px; flex: 0 0 auto; gap: 7px; padding: 0 9px; font-size: 0.73rem; }
  .topbar { height: 51px; padding: 0 18px; }
  .content { padding: 27px 17px; }
  .page-heading { align-items: flex-start; }
  .page-heading > .demo-tag { margin-top: 3px; }

  .panel-heading { align-items: flex-start; }
  .inventory-panel .panel-heading { flex-direction: column; }
  .search-field { width: 100%; }
  .topbar-date { font-size: 0.67rem; }
}

@media (prefers-reduced-motion: no-preference) {
  .loading-screen .wordmark { animation: breathe 1.5s ease-in-out infinite; }
  @keyframes breathe { 50% { opacity: 0.72; } }
}

.loading-screen { color: #a8a8a8; }

.login-screen {
  background: radial-gradient(ellipse at 50% 35%, #1c1c1c 0, #080808 60%);
}

.login-panel {
  border-color: #303030;
  background: #141414;
  box-shadow: 0 22px 70px rgb(0 0 0 / 32%);
}

.sidebar {
  border-color: #344f3b;
  background: #304b37;
}

.nav-item { color: #e0e9e1; }
.nav-item:hover { background: rgb(255 255 255 / 12%); color: #fff; }
.nav-item-active { background: #dce9d8; color: #2f4b36; font-weight: 700; }
.help-card { border-color: rgb(255 255 255 / 20%); }
.help-icon { background: #dce9d8; color: #35533c; }
.help-card strong { color: #f0f5f0; }
.help-card > span:last-child { color: #c4d3c6; }
.profile-button { border-top-color: rgb(255 255 255 / 20%); }
.avatar { background: #dce9d8; color: #35533c; }
.profile-info strong { color: #f0f5f0; }
.profile-info > span, .logout-mark { color: #c4d3c6; }

.topbar {
  border-bottom-color: #292929;
  background: #0d0d0d;
}

.breadcrumb { color: #aaa; }
.breadcrumb span { color: #666; }
.topbar-date { color: #aaa; }
.page-heading h1, .module-placeholder h1 { color: #f0f0f0; }
.eyebrow { color: #aaa; }
.page-subtitle { color: #aaa; }
.demo-tag { border-color: #383838; background: #181818; color: #bbb; }
.demo-tag > span { background: #b4d66e; }

.panel-card {
  border-color: #303030;
  background: #141414;
}

.panel-heading p { color: #a0a0a0; }
.panel-heading h2 { color: #e5e5e5; }
.text-link { color: #c0d99d; }
thead { background: #1d1d1d; }
th { color: #aaa; }
td { border-top-color: #2d2d2d; color: #bbb; }
.product-name { color: #e0e0e0; }
.product-name span { color: #a0a0a0; }
.status-good { color: #b4d296; }
.status-good > span { background: #a3c77c; }
.status-low { color: #e4b17c; }
.status-low > span { background: #d69b5d; }
.result-count { background: #2c3928; color: #d0e3b4; }
.search-field { border-color: #383838; color: #aaa; }
.search-field input { color: #e0e0e0; }
.search-field input::placeholder { color: #888; }
.empty-result { color: #aaa; }
.placeholder-mark { background: #292929; color: #c5dfa0; }
.module-placeholder > p:not(.eyebrow) { color: #aaa; }
.notice-error { border-color: #593734; background: #30211f; color: #f0a69e; }

.login-copy h1 { color: #f0f0f0; }
.login-form label { color: #ccc; }
.login-form input {
  border-color: #383838;
  background: #0b0b0b;
  color: #eee;
}
.login-form input::placeholder { color: #888; }
.login-form input:focus { border-color: #a8c879; box-shadow: 0 0 0 3px rgb(168 200 121 / 18%); }
.login-button { background: #c5e47c; color: #1a261c; }
.login-button span { color: #314328; }
.login-button:hover { background: #d4ed98; }
.form-error { color: #f09f96; }
.retry-button { color: #f0a69e; }

.management-card { margin-bottom: 18px; }
.management-form {
  display: grid;
  gap: 14px;
  padding: 0 20px 20px;
}
.management-form > label {
  display: grid;
  gap: 7px;
  color: #c8c8c8;
  font-size: 0.75rem;
  font-weight: 650;
}
.management-form input:not([type="checkbox"]),
.management-form select,
.table-input,
.table-select {
  min-width: 0;
  height: 38px;
  padding: 0 10px;
  border: 1px solid #383838;
  border-radius: 7px;
  outline: 0;
  background: #0b0b0b;
  color: #ededed;
  font: inherit;
  font-size: 0.76rem;
}
.management-form input:focus,
.management-form select:focus,
.table-input:focus,
.table-select:focus { border-color: #a8c879; }
.employee-create-form { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.order-form { gap: 20px; }
.order-customer-fields { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
.order-customer-fields label,
.order-line label { display: grid; min-width: 0; gap: 7px; color: #c8c8c8; font-size: 0.75rem; font-weight: 650; }
.order-customer-fields input,
.order-line input,
.order-line select {
  min-width: 0;
  height: 38px;
  padding: 0 10px;
  border: 1px solid #383838;
  border-radius: 7px;
  outline: 0;
  background: #0b0b0b;
  color: #ededed;
  font: inherit;
  font-size: 0.76rem;
}
.order-customer-fields input:focus,
.order-line input:focus,
.order-line select:focus { border-color: #a8c879; }
.order-lines-heading { display: flex; align-items: center; justify-content: space-between; gap: 14px; }
.order-lines-heading h3 { margin: 0; color: #e5e5e5; font-size: 0.87rem; }
.order-lines-heading p { margin: 5px 0 0; color: #999; font-size: 0.7rem; }
.order-lines { display: grid; gap: 10px; }
.order-line { display: grid; grid-template-columns: minmax(220px, 1fr) minmax(110px, 160px) minmax(110px, auto) auto; align-items: end; gap: 12px; padding: 12px; border: 1px solid #303030; border-radius: 8px; background: #101010; }
.order-line-total { display: grid; min-width: 95px; gap: 5px; padding-bottom: 5px; text-align: right; }
.order-line-total > span { color: #999; font-size: 0.65rem; font-weight: 500; }
.order-line-total strong { color: #d4e9a9; font-size: 0.8rem; }
.order-remove { min-height: 38px; }
.order-remove:disabled { cursor: not-allowed; opacity: 0.4; }
.order-empty-products { margin: 0; padding: 15px; color: #aaa; font-size: 0.78rem; }
.order-submit-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding-top: 6px; }
.order-submit-row > strong { color: #e8e8e8; font-size: 1rem; }
.order-success { margin: 0; padding: 11px 13px; border: 1px solid #43533a; border-radius: 7px; background: #1d281a; color: #c9e8a1; font-size: 0.78rem; }
.saved-orders { display: grid; gap: 10px; padding: 0 20px 20px; }
.saved-order { padding: 14px; border: 1px solid #303030; border-radius: 8px; background: #101010; }
.saved-order-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
.saved-order-heading > div { display: grid; gap: 5px; }
.saved-order-heading > div > strong { color: #e6e6e6; font-size: 0.8rem; }
.saved-order-heading span { color: #999; font-size: 0.7rem; }
.saved-order ul { display: grid; gap: 6px; margin: 12px 0 0; padding: 0; list-style: none; }
.saved-order li { display: flex; justify-content: space-between; gap: 12px; color: #bbb; font-size: 0.74rem; }
.saved-order li span { color: #ddd; white-space: nowrap; }
.product-create-form { grid-template-columns: minmax(0, 2fr) minmax(180px, 1fr) auto; align-items: end; }
.markup-form { grid-template-columns: minmax(180px, 240px) auto; align-items: end; }
.warehouse-create-form { grid-template-columns: minmax(0, 1fr) auto; align-items: end; }
.management-button,
.secondary-button {
  width: fit-content;
  min-height: 38px;
  padding: 0 14px;
  border: 0;
  border-radius: 7px;
  background: #c5e47c;
  color: #1a261c;
  cursor: pointer;
  font: inherit;
  font-size: 0.75rem;
  font-weight: 750;
}
.management-button:disabled { cursor: wait; opacity: 0.65; }
.secondary-button { border: 1px solid #444; background: #222; color: #ddd; }
.management-table { min-width: 740px; }
.management-table td { padding-top: 10px; padding-bottom: 10px; }
.product-table { min-width: 620px; }
.product-table .product-name { color: #e1e1e1; font-size: 0.77rem; }
.product-table .product-price-input { max-width: 150px; }
.product-markup-field { display: flex; align-items: center; gap: 8px; }
.product-markup-field .text-link { white-space: nowrap; }
.product-selling-price { color: #d4e9a9; font-weight: 700; }
.product-actions { white-space: nowrap; }
.product-actions button + button { margin-left: 12px; }
.table-input { width: 100%; }
.table-select { max-width: 180px; }
.active-toggle { display: inline-flex; align-items: center; gap: 7px; color: #bbb; }
.active-toggle input,
.permission-list input { accent-color: #c5e47c; }
.role-management { display: grid; grid-template-columns: minmax(0, 1fr) minmax(270px, 0.8fr); gap: 24px; padding: 0 20px 20px; }
.role-list { display: grid; align-content: start; gap: 8px; }
.role-item { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px; border: 1px solid #303030; border-radius: 8px; background: #101010; }
.role-item > div:first-child { display: grid; gap: 5px; }
.role-item strong { color: #e4e4e4; font-size: 0.8rem; }
.role-item > div:first-child span { color: #999; font-size: 0.67rem; }
.role-actions { display: flex; gap: 10px; }
.role-delete { color: #e99b92; }
.role-form { padding: 0; align-content: start; }
.role-form h3 { margin: 0; color: #e5e5e5; font-size: 0.87rem; }
.cash-form { grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: end; }
.cash-form > button { grid-column: 1 / -1; }
.cash-entry-list { display: grid; gap: 8px; padding: 0 20px 20px; }
.cash-entry { display: grid; grid-template-columns: minmax(0, 1fr) auto auto auto; align-items: center; gap: 16px; padding: 12px; border: 1px solid #303030; border-radius: 8px; background: #101010; }
.cash-entry-details { display: grid; min-width: 0; gap: 5px; }
.cash-entry-details strong { overflow: hidden; color: #e5e5e5; font-size: 0.78rem; text-overflow: ellipsis; }
.cash-entry-details span { color: #999; font-size: 0.68rem; }
.cash-entry-type { width: fit-content; padding: 5px 8px; border-radius: 12px; font-size: 0.65rem; font-weight: 700; }
.cash-type-income { background: #25351f; color: #b9d995; }
.cash-type-expense { background: #3a2926; color: #e7a298; }
.cash-entry-amount { color: #e2e2e2; font-size: 0.79rem; white-space: nowrap; }
.cash-entry-actions { display: flex; gap: 10px; }
.cash-empty { margin: 0; padding: 16px 0; color: #999; font-size: 0.78rem; }
.balance-card { display: grid; gap: 9px; margin-bottom: 18px; padding: 24px; border: 1px solid #4c6040; border-radius: 10px; background: linear-gradient(135deg, #20291c, #151a13); }
.balance-card > span { color: #bac8ad; font-size: 0.78rem; }
.balance-card > strong { color: #e1f0cc; font-size: clamp(1.8rem, 4vw, 2.6rem); letter-spacing: -0.04em; }
.balance-table { min-width: 720px; }
.balance-income { color: #b9d995 !important; font-weight: 700; }
.balance-expense { color: #e7a298 !important; font-weight: 700; }
.permission-list { display: grid; gap: 10px; margin: 0; padding: 12px; border: 1px solid #303030; border-radius: 8px; }
.permission-list legend { padding: 0 5px; color: #c8c8c8; font-size: 0.73rem; font-weight: 650; }
.permission-list label { display: flex; align-items: flex-start; gap: 8px; color: #bcbcbc; font-size: 0.72rem; line-height: 1.4; }
.permission-list input { margin: 2px 0 0; }
.form-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.warehouse-table-card { margin-bottom: 12px; }
.warehouse-table { min-width: 720px; }
.warehouse-table th,
.warehouse-table td { min-width: 500px; padding: 12px 14px; border-top: 1px solid #2d2d2d; text-align: left; vertical-align: top; white-space: normal; }
.warehouse-table thead th { border-top: 0; background: #1d1d1d; color: #e5e5e5; font-size: 0.7rem; letter-spacing: normal; }
.warehouse-table .member-column { position: sticky; left: 0; z-index: 1; min-width: 190px; background: #171717; }
.warehouse-table thead .member-column { z-index: 2; background: #202020; }
.warehouse-table .member-column strong,
.warehouse-table .member-column span { display: block; }
.warehouse-table .member-column strong { color: #e0e0e0; font-size: 0.77rem; }
.warehouse-table .member-column span { margin-top: 5px; color: #a0a0a0; font-size: 0.66rem; font-weight: 400; }
.warehouse-heading { display: grid; justify-items: start; gap: 6px; }
.warehouse-heading input { width: min(100%, 240px); padding: 7px 8px; border: 1px solid #383838; border-radius: 6px; background: #0b0b0b; color: #ededed; font: inherit; font-size: 0.72rem; font-weight: 700; }
.warehouse-fields { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 9px; }
.warehouse-fields label { display: grid; gap: 5px; color: #9f9f9f; font-size: 0.65rem; font-weight: 650; }
.warehouse-fields input,
.warehouse-fields textarea { display: block; width: 100%; min-width: 0; min-height: 36px; resize: vertical; padding: 8px; border: 1px solid #383838; border-radius: 6px; outline: 0; background: #0b0b0b; color: #ededed; font: inherit; font-size: 0.75rem; line-height: 1.45; }
.warehouse-fields input:focus,
.warehouse-fields textarea:focus { border-color: #a8c879; }
.warehouse-fields label > span { color: #bbb; font-size: 0.75rem; font-weight: 400; white-space: pre-wrap; }
.warehouse-empty { padding: 28px 20px; color: #aaa; font-size: 0.8rem; text-align: center; }
.rank-order-list { display: grid; gap: 7px; margin: 0; padding: 0 20px 20px; list-style: none; }
.rank-order-list li { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 12px; border: 1px solid #303030; border-radius: 7px; background: #101010; color: #ddd; font-size: 0.78rem; }
.rank-order-list li strong { margin-right: 8px; color: #b9d68a; }
.rank-actions { display: flex; gap: 6px; }
.rank-button { width: 32px; min-height: 30px; padding: 0; }
.rank-button:disabled { cursor: not-allowed; opacity: 0.4; }
.notice-board { margin-bottom: 22px; }
.notice-board-heading { margin-bottom: 12px; }
.notice-board-heading h2 { margin: 0; color: #e5e5e5; font-size: 1rem; }
.notice-board-heading p { margin: 5px 0 0; color: #a0a0a0; font-size: 0.75rem; }
.notice-section-create { display: flex; gap: 8px; margin-bottom: 12px; }
.notice-section-create input,
.notice-rename-form input,
.notice-compose textarea,
.notice-entry textarea {
  min-width: 0;
  border: 1px solid #383838;
  border-radius: 7px;
  outline: 0;
  background: #0b0b0b;
  color: #ededed;
  font: inherit;
  font-size: 0.75rem;
}
.notice-section-create input { width: min(100%, 280px); height: 38px; padding: 0 10px; }
.notice-section-create input:focus,
.notice-rename-form input:focus,
.notice-compose textarea:focus,
.notice-entry textarea:focus { border-color: #a8c879; }
.notice-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.notice-card { min-width: 0; padding: 15px; border: 1px solid #303030; border-radius: 9px; background: #141414; }
.notice-card-heading { display: flex; min-height: 30px; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
.notice-card-heading h3 { margin: 0; color: #e7e7e7; font-size: 0.85rem; }
.notice-section-actions,
.notice-entry-actions { display: flex; flex-wrap: wrap; gap: 10px; }
.notice-section-actions { justify-content: flex-end; }
.notice-rename-form { display: flex; width: 100%; align-items: center; flex-wrap: wrap; gap: 8px; }
.notice-rename-form input { flex: 1; height: 34px; padding: 0 8px; }
.notice-list { display: grid; gap: 9px; }
.notice-entry { padding: 10px; border: 1px solid #2d2d2d; border-radius: 7px; background: #101010; }
.notice-entry-meta { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
.notice-entry-meta strong { color: #d7e7bf; font-size: 0.7rem; }
.notice-entry-meta time { color: #858585; font-size: 0.62rem; }
.notice-entry > p { margin: 8px 0; color: #d0d0d0; font-size: 0.75rem; line-height: 1.5; white-space: pre-wrap; overflow-wrap: anywhere; }
.notice-entry textarea,
.notice-compose textarea { display: block; width: 100%; min-height: 65px; resize: vertical; padding: 9px; line-height: 1.45; }
.notice-entry textarea { margin: 8px 0; }
.notice-compose { display: grid; justify-items: start; gap: 8px; margin-top: 10px; }
.notice-compose label { color: #aaa; font-size: 0.68rem; font-weight: 650; }
.notice-compose textarea { min-height: 72px; }
.notice-empty { margin: 0; padding: 8px 2px; color: #858585; font-size: 0.72rem; }

@media (max-width: 700px) {
  .sidebar { border-bottom-color: #344f3b; }
  .employee-create-form,
  .order-customer-fields,
  .order-line,
  .product-create-form,
  .markup-form,
  .cash-form,
  .role-management { grid-template-columns: 1fr; }
  .cash-form > button { grid-column: auto; }
  .cash-entry { grid-template-columns: minmax(0, 1fr) auto; gap: 10px; }
  .cash-entry-amount { justify-self: end; }
  .cash-entry-actions { grid-column: 1 / -1; }
  .balance-table { min-width: 680px; }
  .notice-grid { grid-template-columns: 1fr; }
  .notice-section-create { align-items: stretch; flex-direction: column; }
  .notice-section-create input,
  .notice-section-create .secondary-button { width: 100%; }
  .order-line-total { padding-bottom: 0; text-align: left; }
  .order-submit-row { align-items: flex-start; flex-direction: column; }
  .warehouse-create-form { grid-template-columns: 1fr; }
  .role-item { align-items: flex-start; flex-direction: column; }
}
</style>
