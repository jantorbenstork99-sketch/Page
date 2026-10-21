<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'

const csrfToken = ref('')
const session = ref(null)
const loading = ref(true)
const error = ref('')
const notice = ref('')
const busy = ref(false)
const authMode = ref('code')
const loginCode = ref('')
const loginName = ref('')
const adminUsername = ref('')
const adminPassword = ref('')
const sellerCode = ref('')
const auctions = ref([])
const accessCodes = ref([])
const notifications = ref([])
const unreadNotificationCount = ref(0)
const auditLogs = ref([])
const auditLogsHasMore = ref(false)
const auditLogsNextBefore = ref(null)
const loadingAuditLogs = ref(false)
const auditLogSearch = ref('')
const activeTab = ref('market')
const bidValues = ref({})
const auctionEndDurations = ref({})
const bulkAuctionEndDuration = ref({ value: 5, unit: 'minutes' })
const countdownNow = ref(Date.now())
const auctionForm = ref({ title: '', category: 'Waffen', description: '', startingPrice: '' })
const newCodeType = ref('access')
const newCodeGroup = ref('')
const newCode = ref('')
let refreshTimer = null
let countdownTimer = null

const isAdmin = computed(() => session.value?.isAdmin === true)
const canSell = computed(() => session.value?.canSell === true)
const activeAuctions = computed(() => auctions.value.filter((item) => item.status === 'active' && item.isApproved))
const pendingAuctions = computed(() => auctions.value.filter((item) => item.status === 'active' && !item.isApproved))
const approvedAuctions = computed(() => auctions.value.filter((item) => item.status === 'active' && item.isApproved))
const closedAuctions = computed(() => auctions.value.filter((item) => item.status === 'closed'))
const groupedAccessCodes = computed(() => {
  const groups = new Map()
  for (const code of accessCodes.value) {
    const groupName = code.groupName || 'Unsortiert'
    if (!groups.has(groupName)) groups.set(groupName, [])
    groups.get(groupName).push(code)
  }
  return [...groups.entries()].map(([name, codes]) => ({ name, codes }))
})
const filteredAuditLogs = computed(() => {
  const query = auditLogSearch.value.trim().toLocaleLowerCase('de')
  if (!query) return auditLogs.value
  return auditLogs.value.filter((entry) =>
    [entry.actorName, entry.action, entry.resource, entry.detail, entry.resourceId]
      .filter(Boolean)
      .some((value) => String(value).toLocaleLowerCase('de').includes(query)),
  )
})
const auctionLogCount = computed(() => auditLogs.value.filter((entry) => entry.resource === 'Auktion').length)
const loginLogCount = computed(() => auditLogs.value.filter((entry) => entry.action === 'Angemeldet').length)
const money = (value) => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'USD' }).format(Number(value || 0))
const date = (value) => new Intl.DateTimeFormat('de-DE', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value.endsWith('Z') ? value : `${value.replace(' ', 'T')}Z`))
function auditActionIcon(action) {
  if (['Angemeldet', 'Abgemeldet'].includes(action)) return '↗'
  if (['Erstellt', 'Eingereicht'].includes(action)) return '＋'
  if (['Geboten'].includes(action)) return '＄'
  if (['Gestartet', 'Beendet'].includes(action)) return '◈'
  if (['Gesperrt', 'Gelöscht'].includes(action)) return '×'
  return '⌁'
}
function auditActionTone(action) {
  if (['Gesperrt', 'Gelöscht'].includes(action)) return 'danger'
  if (['Geboten', 'Gestartet', 'Beendet'].includes(action)) return 'auction'
  if (['Angemeldet', 'Abgemeldet'].includes(action)) return 'account'
  return 'default'
}
function auctionRemaining(auction) {
  if (!auction.endsAt) return ''
  const seconds = Math.max(0, Math.ceil((Date.parse(auction.endsAt) - countdownNow.value) / 1000))
  const minutes = Math.floor(seconds / 60)
  const remainingSeconds = seconds % 60
  return minutes ? `${minutes} Min. ${remainingSeconds} Sek.` : `${remainingSeconds} Sek.`
}
function auctionEndDurationSeconds(auctionId) {
  const value = Number(auctionEndDurations.value[auctionId]?.value ?? 5)
  const unit = auctionEndDurations.value[auctionId]?.unit ?? 'minutes'
  return Math.round(value * (unit === 'minutes' ? 60 : 1))
}
function bulkAuctionEndDurationSeconds() {
  const value = Number(bulkAuctionEndDuration.value.value)
  const unit = bulkAuctionEndDuration.value.unit
  return Math.round(value * (unit === 'minutes' ? 60 : 1))
}
function initializeAuctionDurations(items) {
  for (const auction of items) {
    if (!auctionEndDurations.value[auction.id]) {
      auctionEndDurations.value[auction.id] = { value: 5, unit: 'minutes' }
    }
  }
}
function scrollToAuctions() {
  document.querySelector('#live-auctions')?.scrollIntoView({ behavior: 'smooth' })
}
async function copyCode() {
  if (navigator.clipboard) await navigator.clipboard.writeText(newCode.value)
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
  if (!response.ok) throw new Error(data.error || 'Die Anfrage konnte nicht verarbeitet werden.')
  return data
}

async function refreshAuctions() {
  const data = await request('/api/auctions')
  auctions.value = data.auctions
  initializeAuctionDurations(data.auctions)
}

async function refreshCodes() {
  if (isAdmin.value) {
    const data = await request('/api/access-codes')
    accessCodes.value = data.codes
  }
}

async function refreshNotifications() {
  if (!session.value) return
  const data = await request('/api/notifications')
  notifications.value = data.notifications
  unreadNotificationCount.value = data.unreadCount
}

async function markNotificationRead(notification) {
  error.value = ''
  try {
    await request(`/api/notifications/${notification.id}/read`, { method: 'POST', body: '{}' })
    await refreshNotifications()
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function refreshAuditLogs(loadMore = false) {
  if (!isAdmin.value || loadingAuditLogs.value) return
  loadingAuditLogs.value = true
  error.value = ''
  try {
    const before = loadMore ? auditLogsNextBefore.value : null
    const data = await request(`/api/admin/logs${before ? `?before=${before}` : ''}`)
    auditLogs.value = loadMore ? [...auditLogs.value, ...data.logs] : data.logs
    auditLogsHasMore.value = data.hasMore
    auditLogsNextBefore.value = data.nextBefore
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    loadingAuditLogs.value = false
  }
}

async function loadSession() {
  loading.value = true
  error.value = ''
  try {
    const data = await request('/api/session')
    session.value = data.user
    csrfToken.value = data.csrfToken
    if (data.user) {
      await refreshAuctions()
      await refreshNotifications()
      await refreshCodes()
      if (isAdmin.value) await refreshAuditLogs()
    }
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    loading.value = false
  }
}

async function login() {
  error.value = ''
  busy.value = true
  try {
    const data = authMode.value === 'admin'
      ? await request('/api/login', {
        method: 'POST',
        body: JSON.stringify({ username: adminUsername.value, password: adminPassword.value }),
      })
      : await request('/api/access-login', {
        method: 'POST',
        body: JSON.stringify({ code: loginCode.value, name: loginName.value }),
      })
    session.value = data.user
    csrfToken.value = data.csrfToken
    loginCode.value = ''
    adminPassword.value = ''
    await refreshAuctions()
    await refreshNotifications()
    await refreshCodes()
    if (isAdmin.value) await refreshAuditLogs()
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    busy.value = false
  }
}

async function activateSellerAccess() {
  error.value = ''
  busy.value = true
  try {
    const data = await request('/api/seller-access', {
      method: 'POST',
      body: JSON.stringify({ code: sellerCode.value }),
    })
    session.value = data.user
    sellerCode.value = ''
    notice.value = 'Verkäuferzugang freigeschaltet.'
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    busy.value = false
  }
}

async function createAuction() {
  error.value = ''
  notice.value = ''
  busy.value = true
  try {
    const result = await request('/api/auctions', { method: 'POST', body: JSON.stringify(auctionForm.value) })
    auctionForm.value = { title: '', category: 'Waffen', description: '', startingPrice: '' }
    notice.value = result.message
    await refreshAuctions()
    if (isAdmin.value) await refreshAuditLogs()
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    busy.value = false
  }
}

async function placeBid(auction) {
  error.value = ''
  notice.value = ''
  busy.value = true
  try {
    await request(`/api/auctions/${auction.id}/bid`, {
      method: 'POST',
      body: JSON.stringify({ amount: bidValues.value[auction.id] }),
    })
    bidValues.value[auction.id] = ''
    notice.value = 'Dein Gebot wurde abgegeben.'
    await refreshAuctions()
    if (isAdmin.value) await refreshAuditLogs()
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    busy.value = false
  }
}

async function setAuctionStatus(auction, status) {
  error.value = ''
  try {
    const result = await request(`/api/auctions/${auction.id}`, {
      method: 'PATCH',
      body: JSON.stringify({
        status,
        ...(status === 'ending' ? { durationSeconds: auctionEndDurationSeconds(auction.id) } : {}),
      }),
    })
    notice.value = result.message
    await refreshAuctions()
    if (isAdmin.value) await refreshAuditLogs()
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function approveAllAuctions() {
  error.value = ''
  busy.value = true
  try {
    const result = await request('/api/auctions/approve-all', { method: 'POST', body: '{}' })
    notice.value = result.message
    await refreshAuctions()
    await refreshAuditLogs()
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    busy.value = false
  }
}

async function endAllAuctions() {
  error.value = ''
  notice.value = ''
  busy.value = true
  try {
    const result = await request('/api/auctions/end-all', {
      method: 'POST',
      body: JSON.stringify({ durationSeconds: bulkAuctionEndDurationSeconds() }),
    })
    notice.value = result.message
    await refreshAuctions()
    await refreshAuditLogs()
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    busy.value = false
  }
}

async function createCode() {
  error.value = ''
  notice.value = ''
  try {
    const data = await request('/api/access-codes', {
      method: 'POST',
      body: JSON.stringify({ type: newCodeType.value, groupName: newCodeGroup.value }),
    })
    newCode.value = data.code
    newCodeGroup.value = ''
    await refreshCodes()
    await refreshAuditLogs()
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function revokeCode(code) {
  error.value = ''
  try {
    await request(`/api/access-codes/${code.id}`, { method: 'DELETE' })
    await refreshCodes()
    await refreshAuctions()
    await refreshAuditLogs()
  } catch (requestError) {
    error.value = requestError.message
  }
}

async function logout() {
  try {
    const data = await request('/api/logout', { method: 'POST' })
    session.value = data.user
    csrfToken.value = data.csrfToken
    auctions.value = []
    notifications.value = []
    unreadNotificationCount.value = 0
    accessCodes.value = []
    activeTab.value = 'market'
    newCode.value = ''
  } catch (requestError) {
    error.value = requestError.message
  }
}

onMounted(() => {
  loadSession()
  countdownTimer = window.setInterval(() => { countdownNow.value = Date.now() }, 1000)
  refreshTimer = window.setInterval(() => {
    if (session.value) {
      Promise.all([refreshAuctions(), refreshNotifications()])
        .catch((requestError) => { error.value = requestError.message })
    }
  }, 4000)
})

onUnmounted(() => {
  if (refreshTimer !== null) window.clearInterval(refreshTimer)
  if (countdownTimer !== null) window.clearInterval(countdownTimer)
})
</script>

<template>
  <main v-if="loading" class="loading-view">
    <span class="brand-symbol">N</span>
    <p>Verbindung zum Netzwerk wird hergestellt …</p>
  </main>

  <main v-else-if="!session" class="gate-screen">
    <div class="gate-grain"></div>
    <section class="gate-panel">
      <a class="brand-lockup" href="/" aria-label="NACHTMARKT">
        <span class="brand-symbol">N</span>
        <span>NACHT<span>HAFEN</span></span>
      </a>
      <p class="kicker">GESCHLOSSENES NETZWERK <i></i></p>
      <h1>Der Markt<br />öffnet sich <em>erst nach Einbruch der Nacht.</em></h1>
      <p class="gate-description">Ein privater Marktplatz für Güter und Geschichten. Der Zugang ist nur mit persönlichem Code möglich.</p>
      <div class="auth-switch">
        <button :class="{ selected: authMode === 'code' }" type="button" @click="authMode = 'code'">Mit Zugangscode</button>
        <button :class="{ selected: authMode === 'admin' }" type="button" @click="authMode = 'admin'">Admin</button>
      </div>
      <form class="gate-form" @submit.prevent="login">
        <template v-if="authMode === 'code'">
          <label for="member-name">Deckname</label>
          <input id="member-name" v-model.trim="loginName" autocomplete="nickname" minlength="2" maxlength="32" placeholder="Dein Name im Netzwerk" required />
          <label for="access-code">Mitglieder- oder Verkäufer-Code</label>
          <input id="access-code" v-model.trim="loginCode" autocomplete="one-time-code" maxlength="64" placeholder="MIT-… oder VER-…" required />
          <p class="login-code-hint">Mitglieder-Codes beginnen mit MIT-, Verkäufer-Codes mit VER-. Beide Code-Arten ermöglichen die direkte Anmeldung.</p>
        </template>
        <template v-else>
          <label for="admin-name">Admin-Benutzername</label>
          <input id="admin-name" v-model.trim="adminUsername" autocomplete="username" placeholder="Benutzername" required />
          <label for="admin-password">Passwort</label>
          <input id="admin-password" v-model="adminPassword" type="password" autocomplete="current-password" placeholder="Admin-Passwort" required />
        </template>
        <p v-if="error" class="form-error" role="alert">{{ error }}</p>
        <button class="button-primary gate-submit" type="submit" :disabled="busy || !csrfToken">
          {{ busy ? 'Wird geprüft …' : 'Netzwerk betreten' }} <span>↗</span>
        </button>
      </form>
      <p class="gate-footnote">Keine offene Registrierung · Codes werden vom Netzwerk-Admin vergeben.</p>
    </section>
    <span class="gate-index">NH / 001 — PRIVATES NETZWERK</span>
  </main>

  <main v-else class="app-shell">
    <aside class="sidebar">
      <a class="brand-lockup" href="/" @click.prevent="activeTab = 'market'">
        <span class="brand-symbol">N</span>
        <span>NACHT<span>HAFEN</span></span>
      </a>
      <p class="side-caption">DEIN NETZWERK</p>
      <nav>
        <button :class="{ active: activeTab === 'market' }" type="button" @click="activeTab = 'market'"><span>◈</span> Marktplatz <b>{{ activeAuctions.length }}</b></button>
        <button :class="{ active: activeTab === 'notifications' }" type="button" :aria-label="unreadNotificationCount ? `Nachrichten, ${unreadNotificationCount} ungelesen` : 'Nachrichten'" @click="activeTab = 'notifications'; refreshNotifications()"><span>✉</span> Nachrichten <span v-if="unreadNotificationCount" class="notification-badge">{{ unreadNotificationCount > 99 ? '99+' : unreadNotificationCount }}</span></button>
        <button v-if="canSell" :class="{ active: activeTab === 'sell' }" type="button" @click="activeTab = 'sell'"><span>＋</span> Angebot einstellen</button>
        <button v-if="isAdmin" :class="{ active: activeTab === 'approvals' }" type="button" @click="activeTab = 'approvals'"><span>✓</span> Freigaben <b v-if="pendingAuctions.length">{{ pendingAuctions.length }}</b></button>
        <button v-if="isAdmin" :class="{ active: activeTab === 'auction-control' }" type="button" @click="activeTab = 'auction-control'"><span>◷</span> Auktionen</button>
        <button v-if="isAdmin" :class="{ active: activeTab === 'admin' }" type="button" @click="activeTab = 'admin'"><span>⌘</span> Netzwerk-Admin</button>
        <button v-if="isAdmin" :class="{ active: activeTab === 'logs' }" type="button" @click="activeTab = 'logs'; refreshAuditLogs()"><span>≡</span> Aktivitätsprotokoll</button>
      </nav>
      <div class="sidebar-bottom">
        <button class="logout-button" type="button" @click="logout">Abmelden <span>↗</span></button>
      </div>
    </aside>

    <section class="main-area">
      <header class="topbar">
        <div class="mobile-brand"><span class="brand-symbol">N</span> NACHTHAFEN</div>
        <p><span class="status-dot"></span> PRIVATES NETZWERK <span class="topbar-divider">/</span> {{ session.name }}</p>
        <div class="topbar-user"><span class="avatar">{{ session.name.slice(0, 1).toLocaleUpperCase('de') }}</span><span>{{ session.isAdmin ? 'Netzwerk-Admin' : canSell ? 'Verkäuferzugang' : 'Mitglied' }}</span></div>
      </header>

      <div class="page-content">
        <div v-if="error" class="toast toast-error" role="alert">{{ error }}<button type="button" @click="error = ''">×</button></div>
        <div v-if="notice" class="toast" role="status">{{ notice }}<button type="button" @click="notice = ''">×</button></div>

        <template v-if="activeTab === 'market'">
          <section class="hero">
            <div class="hero-copy">
              <p class="kicker">DIE NACHT IST NOCH JUNG <i></i></p>
              <h1>Was suchst<br />du <em>wirklich?</em></h1>
              <p>Seltene Fundstücke, geheime Sammlungen und Geschichten, die den Besitzer wechseln.</p>
              <button class="hero-link" type="button" @click="scrollToAuctions">Auktionen entdecken <span>↓</span></button>
            </div>
            <div class="hero-art" aria-label="Abstrakte nächtliche Stadtillustration">
              <div class="moon"></div><div class="orb orb-one"></div><div class="orb orb-two"></div>
              <div class="skyline skyline-back"><i v-for="n in 9" :key="`b${n}`"></i></div>
              <div class="skyline skyline-front"><i v-for="n in 8" :key="`f${n}`"></i></div>
              <span class="art-label">NACHTHAFEN</span>
              <span class="art-vertical">DEIN ZUGANG. DEINE GESCHICHTE.</span>
            </div>
            <div class="hero-count"><strong>{{ activeAuctions.length.toString().padStart(2, '0') }}</strong><span>AKTIVE<br />AUKTIONEN</span></div>
          </section>

          <section class="seller-access panel" v-if="!canSell && !isAdmin">
            <div><p class="kicker">DU HAST ETWAS ANZUBIETEN?</p><h2>Mach deine Sammlung zum Gesprächsthema.</h2><p>Du kannst einen Verkäufer-Code hier nachträglich mit deinem Mitgliedskonto verbinden. Verkäufer können sich auch direkt mit ihrem VER-Code anmelden.</p></div>
            <form @submit.prevent="activateSellerAccess"><input v-model.trim="sellerCode" aria-label="Verkäufer-Code" placeholder="Verkäufer-Code" required /><button class="button-primary" type="submit" :disabled="busy">Freischalten <span>↗</span></button></form>
          </section>

          <section id="live-auctions" class="listing-section">
            <div class="section-heading">
              <div><p class="kicker">AUSGEWÄHLT FÜR DICH</p><h2>Die laufenden <em>Auktionen</em></h2></div>
              <div class="live-label"><span class="status-dot"></span> LIVE IM NETZWERK</div>
            </div>
            <div v-if="activeAuctions.length" class="auction-grid">
              <article v-for="(auction, index) in activeAuctions" :key="auction.id" class="auction-card">
                <div class="item-art" :class="`art-${index % 5}`">
                  <span class="item-number">NH—{{ String(auction.id).padStart(3, '0') }}</span>
                  <span class="item-glyph">{{ ['✳', '◈', '⌁', '✦', '◉'][index % 5] }}</span>
                  <span class="item-category">{{ auction.category }}</span>
                </div>
                <div class="auction-info">
                  <div class="auction-meta"><span>{{ auction.category }}</span><span>{{ auction.bidCount }} GEBOTE</span></div>
                  <h3>{{ auction.title }}</h3>
                  <p class="item-description">{{ auction.description }}</p>
                  <p class="seller-line">Eingestellt von <strong>{{ auction.sellerName }}</strong></p>
                  <div class="bid-row"><div><small>AKTUELLES GEBOT</small><strong>{{ money(auction.currentBid) }}</strong></div><span v-if="auction.endsAt" class="market-countdown">LETZTE RUNDE · {{ auctionRemaining(auction) }}</span></div>
                  <form v-if="auction.sellerId !== session.id && !isAdmin" class="bid-form" @submit.prevent="placeBid(auction)">
                    <label class="sr-only" :for="`bid-${auction.id}`">Dein Gebot in US-Dollar</label>
                    <input :id="`bid-${auction.id}`" v-model="bidValues[auction.id]" type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?" :placeholder="`Mind. ${money(Number(auction.currentBid) + 0.01)}`" required />
                    <button class="button-primary" type="submit" :disabled="busy">Bieten <span>↗</span></button>
                  </form>
                  <div v-else-if="isAdmin" class="own-listing"><span>Admin-Steuerung</span><button type="button" @click="activeTab = 'auction-control'">Auktion beenden</button></div>
                  <div v-else class="own-listing">Dein Angebot · Bieten nicht möglich</div>
                </div>
              </article>
            </div>
            <div v-else class="empty-state panel"><span>◈</span><h3>Die Nacht ist noch ruhig.</h3><p>Aktuell gibt es keine laufenden Auktionen. Schau später wieder vorbei.</p></div>
          </section>

          <section v-if="closedAuctions.length" class="listing-section archive-section">
            <div class="section-heading"><div><p class="kicker">DIE GESCHICHTE GEHT WEITER</p><h2>Abgeschlossene <em>Auktionen</em></h2></div></div>
            <div class="archive-list"><article v-for="auction in closedAuctions" :key="auction.id"><span>{{ auction.category }}</span><strong>{{ auction.title }}</strong><span>{{ auction.sellerId === session.id ? (auction.buyerName ? `Gekauft von ${auction.buyerName} für ${money(auction.soldAmount)}` : 'Ohne Gebot beendet – nicht verkauft') : (auction.bidCount ? `Endgebot ${money(auction.currentBid)}` : 'Ohne Gebot beendet') }}</span><span>von {{ auction.sellerName }}</span></article></div>
          </section>
        </template>

        <template v-else-if="activeTab === 'notifications'">
          <section class="section-heading standalone-heading">
            <div><p class="kicker">PERSÖNLICHE NACHRICHTEN</p><h1>Dein <em>Posteingang</em></h1><p>Hinweise zu deinen Geboten und Auktionen.</p></div>
            <span class="notification-counter">{{ unreadNotificationCount }} ungelesen</span>
          </section>
          <section class="panel notification-panel">
            <article v-for="notification in notifications" :key="notification.id" class="notification-row" :class="{ unread: !notification.isRead }">
              <span class="notification-dot" :class="{ 'notification-dot-unread': !notification.isRead }"></span>
              <div><p>{{ notification.message }}</p><time>{{ date(notification.createdAt) }}</time></div>
              <button v-if="!notification.isRead" type="button" @click="markNotificationRead(notification)">Als gelesen markieren</button>
              <span v-else class="read-label">Gelesen</span>
            </article>
            <p v-if="notifications.length === 0" class="notification-empty">Du hast noch keine Nachrichten.</p>
          </section>
        </template>

        <template v-else-if="activeTab === 'sell' && canSell">
          <section class="section-heading standalone-heading"><div><p class="kicker">DEINE SAMMLUNG, DEINE REGELN</p><h1>Ein Angebot <em>einstellen</em></h1><p>Beschreibe deinen fiktiven Gegenstand und lege den Startpreis fest.</p></div></section>
          <form class="panel listing-form" @submit.prevent="createAuction">
            <label>Titel des Angebots<input v-model.trim="auctionForm.title" maxlength="80" placeholder="z. B. Die silberne Taschenuhr" required /></label>
            <label>Kategorie<select v-model="auctionForm.category"><option>Waffen</option><option>Drogen</option></select></label>
            <label>Beschreibung (optional)<textarea v-model.trim="auctionForm.description" maxlength="1000" rows="5" placeholder="Was macht dieses Stück besonders?"></textarea></label>
            <label>Startgebot in US-Dollar<input v-model="auctionForm.startingPrice" type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?" placeholder="25.00" required /></label>
            <p class="fiction-disclaimer">„Waffen“ und „Drogen“ sind ausschließlich fiktive Kategorien für Inhalte innerhalb des Roleplay-Projekts. Es werden keine realen Waren oder Transaktionen angeboten.</p>
            <button class="button-primary" type="submit" :disabled="busy">Auktion veröffentlichen <span>↗</span></button>
          </form>
        </template>

        <template v-else-if="activeTab === 'approvals' && isAdmin">
          <section class="section-heading standalone-heading">
            <div><p class="kicker">ADMIN · ANGEBOTE PRÜFEN</p><h1>Auktions<em>freigaben</em></h1><p>Prüfe eingereichte Angebote und starte sie einzeln oder gemeinsam.</p></div>
            <button v-if="pendingAuctions.length" class="button-primary" type="button" :disabled="busy" @click="approveAllAuctions">Alle freigeben & starten ({{ pendingAuctions.length }}) <span>↗</span></button>
          </section>
          <section class="panel admin-panel">
            <div v-if="pendingAuctions.length" class="pending-auction-list">
              <article v-for="auction in pendingAuctions" :key="auction.id" class="pending-auction-row">
                <div><span class="code-type">{{ auction.category }}</span><strong>{{ auction.title }}</strong><small>Von {{ auction.sellerName }} · Startgebot {{ money(auction.startingPrice) }}</small><p v-if="auction.description">{{ auction.description }}</p></div>
                <button class="button-primary" type="button" :disabled="busy" @click="setAuctionStatus(auction, 'active')">Auktion starten <span>↗</span></button>
              </article>
            </div>
            <p v-else class="empty-copy">Keine Angebote warten auf Freigabe.</p>
          </section>
        </template>

        <template v-else-if="activeTab === 'auction-control' && isAdmin">
          <section class="section-heading standalone-heading"><div><p class="kicker">ADMIN · LAUFENDE AUKTIONEN</p><h1>Auktionen <em>steuern</em></h1><p>Starte eine letzte Gebotsphase. Alle bisherigen Bieter erhalten eine Nachricht und können bis zum Ablauf noch bieten.</p></div></section>
          <section v-if="approvedAuctions.length" class="panel bulk-auction-control">
            <div><span class="code-type">SAMMELAKTION · {{ approvedAuctions.length }} AUKTIONEN</span><strong>Alle laufenden Auktionen mit Timer beenden</strong><p>Setzt den Countdown für alle freigegebenen Auktionen gemeinsam. Bereits laufende Timer werden zurückgesetzt.</p></div>
            <form class="end-auction-form" @submit.prevent="endAllAuctions">
              <label>Dauer der letzten Gebotsphase
                <span class="duration-inputs">
                  <input v-model.number="bulkAuctionEndDuration.value" type="number" :min="bulkAuctionEndDuration.unit === 'seconds' ? 10 : 1" :max="bulkAuctionEndDuration.unit === 'seconds' ? 86400 : 1440" step="1" aria-label="Dauer für alle Auktionen" required />
                  <select v-model="bulkAuctionEndDuration.unit" aria-label="Zeiteinheit für alle Auktionen"><option value="seconds">Sekunden</option><option value="minutes">Minuten</option></select>
                </span>
              </label>
              <button class="button-primary" type="submit" :disabled="busy">Alle Auktionen beenden <span>⌛</span></button>
            </form>
          </section>
          <section class="panel admin-panel">
            <div v-if="approvedAuctions.length" class="auction-admin-list">
              <article v-for="auction in approvedAuctions" :key="auction.id" class="auction-admin-card">
                <div class="auction-admin-header">
                  <div class="auction-control-info">
                    <span class="code-type">{{ auction.category }}</span>
                    <strong>{{ auction.title }}</strong>
                    <small>Von {{ auction.sellerName }} · Startgebot {{ money(auction.startingPrice) }}</small>
                  </div>
                  <div class="auction-admin-current">
                    <span>HÖCHSTGEBOT</span>
                    <strong>{{ money(auction.currentBid) }}</strong>
                    <small>{{ auction.bidCount }} {{ auction.bidCount === 1 ? 'Gebot' : 'Gebote' }}</small>
                  </div>
                </div>
                <div class="auction-admin-footer">
                  <span v-if="auction.endsAt" class="closing-countdown">Letzte Gebotsphase · endet in {{ auctionRemaining(auction) }}</span>
                  <form v-else class="end-auction-form" @submit.prevent="setAuctionStatus(auction, 'ending')">
                    <label>Dauer der letzten Gebotsphase
                      <span class="duration-inputs">
                        <input v-model.number="auctionEndDurations[auction.id].value" type="number" :min="auctionEndDurations[auction.id].unit === 'seconds' ? 10 : 1" :max="auctionEndDurations[auction.id].unit === 'seconds' ? 86400 : 1440" step="1" aria-label="Dauer der letzten Gebotsphase" required />
                        <select v-model="auctionEndDurations[auction.id].unit" aria-label="Zeiteinheit"><option value="seconds">Sekunden</option><option value="minutes">Minuten</option></select>
                      </span>
                    </label>
                    <button class="button-primary" type="submit" :disabled="busy">Auktion beenden <span>⌛</span></button>
                  </form>
                  <span v-if="auction.endsAt" class="closing-status">Timer aktiv</span>
                </div>
                <details class="auction-bid-history">
                  <summary class="auction-bid-heading">
                    <strong>Gebotsverlauf anzeigen</strong>
                    <span class="auction-bid-summary">
                      <span>{{ auction.bidCount }} {{ auction.bidCount === 1 ? 'GEBOT' : 'GEBOTE' }}</span>
                      <span v-if="auction.bids?.length">Höchstbietend: {{ auction.bids[0].bidderName }}</span>
                      <span v-else>Kein Bieter</span>
                    </span>
                  </summary>
                  <div v-if="auction.bids?.length" class="auction-bid-list">
                    <div v-for="(bid, index) in auction.bids" :key="`${auction.id}-${index}`" class="auction-bid-row">
                      <span class="auction-bid-rank">{{ String(auction.bids.length - index).padStart(2, '0') }}</span>
                      <strong>{{ bid.bidderName }}</strong>
                      <time>{{ date(bid.createdAt) }}</time>
                      <span class="auction-bid-amount">{{ money(bid.amount) }}</span>
                    </div>
                  </div>
                  <p v-else class="auction-bid-empty">Noch keine Gebote für diese Auktion.</p>
                </details>
              </article>
            </div>
            <p v-else class="empty-copy">Aktuell laufen keine Auktionen.</p>
          </section>
        </template>

        <template v-else-if="activeTab === 'admin' && isAdmin">
          <section class="section-heading standalone-heading"><div><p class="kicker">ZUGANGSKONTROLLE</p><h1>Netzwerk-<em>Admin</em></h1><p>Erstelle Zugangscodes und sperre Codes, die nicht mehr gültig sein sollen.</p></div></section>
          <section class="panel admin-panel">
            <div class="section-heading compact"><div><p class="kicker">NEUEN CODE ERSTELLEN</p><h2>Einladung ausstellen</h2></div></div>
            <form class="code-create-form" @submit.prevent="createCode">
              <label>Code-Typ<select v-model="newCodeType"><option value="access">Mitglieder-Zugang (MIT-)</option><option value="seller">Verkäufer-Zugang (VER-)</option></select></label>
              <label class="group-field">Gruppenname<span class="group-field-hint">Gruppennamen eintragen</span><span class="group-input-wrap"><span aria-hidden="true">⌘</span><input class="group-name-input" v-model.trim="newCodeGroup" maxlength="80" placeholder="Gruppennamen eingeben …" required /></span></label>
              <button class="button-primary" type="submit" :disabled="!newCodeGroup.trim()">Code erstellen <span>＋</span></button>
            </form>
            <div v-if="newCode" class="new-code-result"><div><span>DEIN NEUER CODE — EINMALIG SICHTBAR</span><code>{{ newCode }}</code></div><button type="button" @click="copyCode">Kopieren</button></div>
          </section>
          <section class="panel admin-panel code-list-panel">
            <div class="section-heading compact"><div><p class="kicker">CODE-ÜBERSICHT</p><h2>Nach Roleplay-Gruppe sortiert</h2></div></div>
            <div class="code-list">
              <section v-for="group in groupedAccessCodes" :key="group.name" class="code-group">
                <h3>{{ group.name }} <span>{{ group.codes.length }} {{ group.codes.length === 1 ? 'Code' : 'Codes' }}</span></h3>
                <article v-for="code in group.codes" :key="code.id" class="code-row">
                  <span class="code-type" :class="{ seller: code.type === 'seller' }">{{ code.type === 'seller' ? 'VERKÄUFER · VER-' : 'MITGLIED · MIT-' }}</span>
                  <div class="code-details"><strong>{{ code.userName || 'Noch nicht eingelöst' }}</strong><small>Erstellt {{ date(code.createdAt) }}</small></div>
                  <span class="code-status" :class="{ revoked: code.revoked }">{{ code.revoked ? 'GESPERRT' : code.userName ? 'EINGELÖST' : 'BEREIT' }}</span>
                  <button v-if="!code.revoked" class="revoke-button" type="button" @click="revokeCode(code)">Sperren</button>
                </article>
              </section>
              <p v-if="!accessCodes.length" class="empty-copy">Noch keine Zugangscodes erstellt.</p>
            </div>
          </section>
        </template>

        <template v-else-if="activeTab === 'logs' && isAdmin">
          <section class="section-heading standalone-heading">
            <div><p class="kicker">NACHTHAFEN · ADMIN</p><h1>Aktivitäts<em>protokoll</em></h1><p>Alle wichtigen Ereignisse im Netzwerk – übersichtlich an einem Ort.</p></div>
            <button class="button-primary" type="button" :disabled="loadingAuditLogs" @click="refreshAuditLogs()">{{ loadingAuditLogs ? 'Wird aktualisiert …' : 'Aktualisieren' }} <span>↻</span></button>
          </section>
          <section class="panel admin-panel log-panel">
            <div class="log-stats">
              <article><span class="log-stat-icon">≡</span><div><small>GELADENE EREIGNISSE</small><strong>{{ auditLogs.length }}</strong></div></article>
              <article><span class="log-stat-icon log-stat-account">↗</span><div><small>ANMELDUNGEN</small><strong>{{ loginLogCount }}</strong></div></article>
              <article><span class="log-stat-icon log-stat-auction">◈</span><div><small>AUKTIONSAKTIVITÄTEN</small><strong>{{ auctionLogCount }}</strong></div></article>
            </div>
            <div class="log-toolbar">
              <label class="log-search"><span aria-hidden="true">⌕</span><input v-model="auditLogSearch" type="search" placeholder="Nach Person, Aktion oder Bereich suchen" aria-label="Aktivitätsprotokoll durchsuchen" /></label>
              <span class="log-retention-note">Codes und Passwörter werden nicht gespeichert.</span>
            </div>
            <div v-if="filteredAuditLogs.length" class="audit-timeline">
              <article v-for="entry in filteredAuditLogs" :key="entry.id" class="audit-entry">
                <div class="audit-entry-mark" :class="`tone-${auditActionTone(entry.action)}`">{{ auditActionIcon(entry.action) }}</div>
                <div class="audit-entry-content">
                  <div class="audit-entry-top">
                    <div class="audit-entry-title">
                      <span class="audit-action-pill" :class="`tone-${auditActionTone(entry.action)}`">{{ entry.action }}</span>
                      <strong>{{ entry.resource }}</strong>
                    </div>
                    <time>{{ date(entry.createdAt) }}</time>
                  </div>
                  <div class="audit-entry-bottom">
                    <span class="audit-actor"><span class="audit-avatar">{{ entry.actorName.slice(0, 1).toLocaleUpperCase('de') }}</span>{{ entry.actorName }}</span>
                    <span v-if="entry.detail || entry.resourceId" class="audit-detail">{{ entry.detail || `Referenz #${entry.resourceId}` }}</span>
                  </div>
                </div>
              </article>
            </div>
            <div v-else-if="!auditLogs.length && !loadingAuditLogs" class="log-empty-state"><span>⌁</span><strong>Noch keine Aktivitäten</strong><p>Sobald im Netzwerk etwas passiert, erscheinen die Ereignisse hier.</p></div>
            <div v-else-if="auditLogs.length" class="log-empty-state search-empty"><span>⌕</span><strong>Keine passenden Einträge</strong><p>Versuche einen anderen Suchbegriff.</p></div>
            <button v-if="auditLogsHasMore" class="load-more-button" type="button" :disabled="loadingAuditLogs" @click="refreshAuditLogs(true)">{{ loadingAuditLogs ? 'Wird geladen …' : 'Ältere Einträge laden' }}</button>
          </section>
        </template>
      </div>
      <footer class="site-footer"><span>© NACHTHAFEN</span></footer>
    </section>
  </main>
</template>
