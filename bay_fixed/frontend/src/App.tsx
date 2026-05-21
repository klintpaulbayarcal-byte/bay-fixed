import React, { useEffect, useMemo, useState } from 'react'

type PageName = 'login' | 'signup' | 'cafe' | 'track'

type Product = {
    id: number
    name: string
    category: string
    description: string
    price: number
    stock: number
    image: string
    featured?: boolean
}

type CartItem = Product & { quantity: number }

type NavigateFn = (path: string) => void

type LoginState = {
    username: string
    password: string
    rememberMe: boolean
    showPassword: boolean
    message: string
    messageType: 'error' | 'success' | ''
    loading: boolean
}

type SignupState = {
    fullname: string
    email: string
    username: string
    password: string
    confirmPassword: string
    message: string
    messageType: 'error' | 'success' | ''
    loading: boolean
}

type ApiProduct = {
    id: number | string
    name: string
    category: string
    description: string
    price: number | string
    image_url?: string
    image?: string
    stock_quantity?: number | string
    stock?: number | string
}

// ─── Fallback menu shown when backend is unreachable ────────────────────────
const fallbackProducts: Product[] = [
    { id: 1, name: 'Americano',           category: 'coffee',    description: 'Espresso topped with hot water.',                    price: 140, stock: 20, image: 'https://images.unsplash.com/photo-1510591509098-f4fdc6d0ff04?w=900&h=700&fit=crop', featured: true },
    { id: 2, name: 'Cappuccino',           category: 'coffee',    description: 'Espresso with steamed milk and foam.',               price: 165, stock: 17, image: 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?w=900&h=700&fit=crop', featured: true },
    { id: 3, name: 'Caramel Macchiato',   category: 'coffee',    description: 'Espresso with steamed milk and caramel drizzle.',    price: 180, stock: 15, image: 'https://images.unsplash.com/photo-1509042239860-f550ce710b93?w=900&h=700&fit=crop', featured: true },
    { id: 4, name: 'Latte',               category: 'coffee',    description: 'Smooth espresso and milk for a creamy finish.',      price: 160, stock: 12, image: 'https://images.unsplash.com/photo-1572442388796-11668a67e53d?w=900&h=700&fit=crop' },
    { id: 5, name: 'Iced Tea Lemon',      category: 'non-coffee', description: 'Refreshing brewed tea with citrus.',                price: 110, stock: 14, image: 'https://images.unsplash.com/photo-1556679343-c7306c1976bc?w=900&h=700&fit=crop' },
    { id: 6, name: 'Chocolate Muffin',    category: 'pastry',    description: 'Freshly baked chocolate muffin.',                   price: 85,  stock: 8,  image: 'https://images.unsplash.com/photo-1588195538326-c5b1e9f80a1b?w=900&h=700&fit=crop' },
    { id: 7, name: 'Ham and Cheese Sandwich', category: 'food',  description: 'Toasted sandwich with ham and cheese.',             price: 180, stock: 6,  image: 'https://images.unsplash.com/photo-1528735602780-2552fd46c7af?w=900&h=700&fit=crop' },
]

function currency(value: number) {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value)
}

function stockLabel(stock: number) {
    if (stock <= 0)  return { text: 'Out of stock', className: 'stock-out' }
    if (stock <= 5)  return { text: `Only ${stock} left!`, className: 'stock-low' }
    return { text: `In stock`, className: 'stock-ok' }
}

function getPageFromHash(hash: string): PageName {
    const h = hash.replace('#', '').replace('/', '').toLowerCase()
    if (h === 'signup') return 'signup'
    if (h === 'track')  return 'track'
    if (h === 'cafe' || h === 'menu' || h === 'order') return 'cafe'
    return 'login'
}

// ─── API URL resolution ──────────────────────────────────────────────────────
// In dev (Vite proxy), /api/... is rewritten to http://localhost/bay/...
// In production, VITE_API_BASE_URL must point to your InfinityFree backend,
// e.g. https://web-proj.42web.io/bay
const apiBase = (import.meta.env.VITE_API_BASE_URL as string | undefined)?.trim().replace(/\/$/, '') ?? ''

function apiUrl(path: string): string {
    // In local Vite dev server: use proxy
    if (import.meta.env.DEV) {
        return `/api${path}`
    }
    // In production: use the configured backend base
    if (apiBase && !apiBase.includes('localhost') && !apiBase.includes('127.0.0.1')) {
        return `${apiBase}${path}`
    }
    // Absolute fallback - direct InfinityFree URL
    return `https://web-proj.42web.io/bay${path}`
}

function mapApiProduct(product: ApiProduct): Product {
    return {
        id: Number(product.id),
        name: product.name,
        category: product.category || 'coffee',
        description: product.description || '',
        price: Number(product.price) || 0,
        stock: Number(product.stock_quantity ?? product.stock ?? 0),
        image: product.image_url || product.image || 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?w=900&h=700&fit=crop',
        featured: ['espresso', 'americano', 'cappuccino', 'latte', 'macchiato'].some(tag =>
            product.name.toLowerCase().includes(tag)
        ),
    }
}

// ─── Login Page ──────────────────────────────────────────────────────────────
function LoginPage({ navigate }: { navigate: NavigateFn }) {
    const [state, setState] = useState<LoginState>({
        username: '', password: '', rememberMe: false,
        showPassword: false, message: '', messageType: '', loading: false,
    })

    async function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault()
        if (!state.username.trim()) return setState(s => ({ ...s, message: 'Username is required', messageType: 'error' }))
        if (!state.password.trim()) return setState(s => ({ ...s, message: 'Password is required', messageType: 'error' }))

        setState(s => ({ ...s, loading: true, message: 'Signing in...', messageType: '' }))

        try {
            // FIX: was hardcoded '/api/login' — now uses apiUrl so it points to InfinityFree
            const response = await fetch(apiUrl('/auth_api.php'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: 'login', username: state.username, password: state.password }),
            })

            const data = await response.json()
            if (!response.ok || !data.success) throw new Error(data.message || 'Login failed')

            setState(s => ({ ...s, loading: false, message: data.message || 'Login successful!', messageType: 'success' }))

            if (data.role === 'admin' || data.role === 'staff') {
                // Redirect to the PHP admin/staff page on InfinityFree
                const backendBase = apiBase && !apiBase.includes('localhost') ? apiBase : 'https://web-proj.42web.io/bay'
                window.location.assign(`${backendBase}/${data.redirect_to || 'staff_panel.php'}`)
                return
            }

            navigate('/cafe')
        } catch (error) {
            setState(s => ({
                ...s, loading: false,
                message: error instanceof Error ? error.message : 'Unable to login. Please try again.',
                messageType: 'error',
            }))
        }
    }

    return (
        <div className="auth-page">
            <div className="signin-shell row g-0">
                <div className="col-md-6 signin-left d-flex flex-column justify-content-center">
                    <span className="brand-chip"><span className="brand-dot" />KLINT&apos;S CAFE</span>
                    <h2>Your Premium<br />Cafe Experience</h2>
                    <p>Staff and customer access to browse the menu, manage orders, and enjoy Klint's Cafe.</p>
                    <ul className="feature-stack">
                        <li>Fresh brewed coffee and pastries daily</li>
                        <li>Easy online ordering for pickup or dine-in</li>
                        <li>Guests can open the cafe menu without logging in</li>
                    </ul>
                </div>
                <div className="col-md-6 signin-right">
                    <form onSubmit={submit}>
                        <h1 className="signin-title h3">Staff Sign In</h1>
                        <p className="signin-subtitle">Access your management dashboard</p>

                        {state.message && (
                            <div className={`alert ${state.messageType === 'error' ? 'alert-danger' : 'alert-success'}`}>
                                {state.message}
                            </div>
                        )}

                        <label htmlFor="username" className="form-label">Username</label>
                        <input type="text" className="form-control mb-3" id="username"
                            value={state.username}
                            onChange={e => setState(s => ({ ...s, username: e.target.value }))}
                            placeholder="Enter username" autoComplete="username" disabled={state.loading} />

                        <label htmlFor="password" className="form-label">Password</label>
                        <div className="input-group mb-3">
                            <input
                                type={state.showPassword ? 'text' : 'password'}
                                className="form-control" id="password"
                                value={state.password}
                                onChange={e => setState(s => ({ ...s, password: e.target.value }))}
                                placeholder="Enter password" autoComplete="current-password" disabled={state.loading} />
                            <button className="btn password-toggle" type="button"
                                onClick={() => setState(s => ({ ...s, showPassword: !s.showPassword }))}>
                                {state.showPassword ? 'Hide' : 'Show'}
                            </button>
                        </div>

                        <div className="form-check mb-4">
                            <input type="checkbox" className="form-check-input" id="rememberMe"
                                checked={state.rememberMe}
                                onChange={e => setState(s => ({ ...s, rememberMe: e.target.checked }))} />
                            <label className="form-check-label" htmlFor="rememberMe">Remember me</label>
                        </div>

                        <button className="btn btn-primary w-100" type="submit" disabled={state.loading}>
                            {state.loading ? 'Signing in…' : 'Sign In'}
                        </button>

                        <div className="auth-link-box">
                            <p>Guest Customer?</p>
                            <button className="btn auth-outline-btn w-100" type="button" onClick={() => navigate('/cafe')}>
                                Browse Menu &amp; Order
                            </button>
                        </div>

                        <p className="text-center signin-footer">
                            New staff? <button type="button" className="text-link-btn" onClick={() => navigate('/signup')}>Request access</button>
                        </p>
                    </form>
                </div>
            </div>
        </div>
    )
}

// ─── Signup Page ─────────────────────────────────────────────────────────────
function SignupPage({ navigate }: { navigate: NavigateFn }) {
    const [state, setState] = useState<SignupState>({
        fullname: '', email: '', username: '', password: '', confirmPassword: '',
        message: '', messageType: '', loading: false,
    })

    async function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault()
        if (!state.fullname.trim()) return setState(s => ({ ...s, message: 'Full name is required', messageType: 'error' }))
        if (!state.email.trim()) return setState(s => ({ ...s, message: 'Email is required', messageType: 'error' }))
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(state.email.trim())) return setState(s => ({ ...s, message: 'Please enter a valid email address', messageType: 'error' }))
        if (!state.username.trim()) return setState(s => ({ ...s, message: 'Username is required', messageType: 'error' }))
        if (state.password.length < 6) return setState(s => ({ ...s, message: 'Password must be at least 6 characters', messageType: 'error' }))
        if (state.password !== state.confirmPassword) return setState(s => ({ ...s, message: 'Passwords do not match', messageType: 'error' }))

        setState(s => ({ ...s, loading: true, message: 'Creating account…', messageType: '' }))

        try {
            const response = await fetch(apiUrl('/auth_api.php'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    action: 'register', fullname: state.fullname, email: state.email,
                    username: state.username, password: state.password, confirmPassword: state.confirmPassword,
                }),
            })

            const data = await response.json()
            if (!response.ok || !data.success) throw new Error(data.message || 'Registration failed')

            setState(s => ({ ...s, loading: false, message: 'Account created! Redirecting to sign in…', messageType: 'success' }))
            window.setTimeout(() => navigate('/'), 1000)
        } catch (error) {
            setState(s => ({
                ...s, loading: false,
                message: error instanceof Error ? error.message : 'Unable to register',
                messageType: 'error',
            }))
        }
    }

    return (
        <div className="auth-page">
            <div className="signup-shell row g-0">
                <div className="col-md-6 signup-left d-flex flex-column justify-content-center">
                    <span className="brand-chip"><span className="brand-dot" />KLINT&apos;S CAFE</span>
                    <h2>Create Your<br />Account</h2>
                    <p>Register to track your orders and enjoy a personalised cafe experience.</p>
                    <ul className="feature-stack">
                        <li>Simple registration with instant validation</li>
                        <li>Track your past and current orders</li>
                        <li>Quick access to your order history</li>
                    </ul>
                </div>
                <div className="col-md-6 signup-right">
                    <form onSubmit={submit}>
                        <h1 className="signup-title h3">Create Account</h1>
                        <p className="signup-subtitle">Enter your details to register.</p>

                        {state.message && <div className={`alert ${state.messageType === 'error' ? 'alert-danger' : 'alert-success'}`}>{state.message}</div>}

                        <label htmlFor="fullname" className="form-label">Full Name</label>
                        <input className="form-control mb-3" id="fullname" value={state.fullname} onChange={e => setState(s => ({ ...s, fullname: e.target.value }))} placeholder="Enter full name" disabled={state.loading} />

                        <label htmlFor="email" className="form-label">Email</label>
                        <input className="form-control mb-3" id="email" type="email" value={state.email} onChange={e => setState(s => ({ ...s, email: e.target.value }))} placeholder="Enter email address" disabled={state.loading} />

                        <label htmlFor="username" className="form-label">Username</label>
                        <input className="form-control mb-3" id="username" value={state.username} onChange={e => setState(s => ({ ...s, username: e.target.value }))} placeholder="Choose a username" disabled={state.loading} />

                        <label htmlFor="password" className="form-label">Password</label>
                        <input className="form-control mb-3" id="password" type="password" value={state.password} onChange={e => setState(s => ({ ...s, password: e.target.value }))} placeholder="At least 6 characters" disabled={state.loading} />

                        <label htmlFor="confirmPassword" className="form-label">Confirm Password</label>
                        <input className="form-control mb-3" id="confirmPassword" type="password" value={state.confirmPassword} onChange={e => setState(s => ({ ...s, confirmPassword: e.target.value }))} placeholder="Repeat your password" disabled={state.loading} />

                        <button className="btn btn-primary w-100 mt-2" type="submit" disabled={state.loading}>
                            {state.loading ? 'Creating account…' : 'Create Account'}
                        </button>

                        <p className="text-center signup-footer">
                            Already have an account? <button type="button" className="text-link-btn" onClick={() => navigate('/')}>Sign In</button>
                        </p>
                    </form>
                </div>
            </div>
        </div>
    )
}

// ─── Track Order Page ─────────────────────────────────────────────────────────
function TrackOrderPage({ navigate }: { navigate: NavigateFn }) {
    const [orderId, setOrderId] = useState('')
    const [identity, setIdentity] = useState('')
    const [status, setStatus] = useState<'idle' | 'loading' | 'success' | 'error'>('idle')
    const [message, setMessage] = useState('')
    const [order, setOrder] = useState<Record<string, unknown> | null>(null)
    const [timeline, setTimeline] = useState<Array<Record<string, unknown>>>([])

    async function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault()
        if (!orderId.trim() || !identity.trim()) {
            setStatus('error')
            setMessage('Order ID and your name or phone are required')
            return
        }

        setStatus('loading')
        setMessage('Checking order status…')

        try {
            const query = new URLSearchParams({ order_id: orderId.trim(), identity: identity.trim() })
            const response = await fetch(`${apiUrl('/order_status_api.php')}?${query.toString()}`, { credentials: 'include' })
            const data = await response.json()
            if (!response.ok || !data.success) throw new Error(data.message || 'Unable to find order')
            setOrder(data.order || null)
            setTimeline(Array.isArray(data.timeline) ? data.timeline : [])
            setStatus('success')
            setMessage('Order found!')
        } catch (error) {
            setOrder(null)
            setTimeline([])
            setStatus('error')
            setMessage(error instanceof Error ? error.message : 'Unable to find order')
        }
    }

    return (
        <div className="auth-page">
            <div className="signup-shell row g-0">
                <div className="col-md-6 signup-left d-flex flex-column justify-content-center">
                    <span className="brand-chip"><span className="brand-dot" />KLINT&apos;S CAFE</span>
                    <h2>Track Your<br />Order Status</h2>
                    <p>Enter your order ID and name or phone to see live updates.</p>
                    <ul className="feature-stack">
                        <li>Live order state from the database</li>
                        <li>Step-by-step timeline updates</li>
                        <li>Use order ID + your name or phone</li>
                    </ul>
                </div>
                <div className="col-md-6 signup-right">
                    <form onSubmit={submit}>
                        <h1 className="signup-title h3">Track Order</h1>
                        <p className="signup-subtitle">Check your current order progress</p>

                        {message && <div className={`alert ${status === 'error' ? 'alert-danger' : 'alert-success'}`}>{message}</div>}

                        <label htmlFor="trackOrderId" className="form-label">Order ID</label>
                        <input id="trackOrderId" className="form-control mb-3" value={orderId} onChange={e => setOrderId(e.target.value)} placeholder="e.g. 25" disabled={status === 'loading'} />

                        <label htmlFor="trackIdentity" className="form-label">Name or Phone</label>
                        <input id="trackIdentity" className="form-control mb-3" value={identity} onChange={e => setIdentity(e.target.value)} placeholder="John Doe or 09xxxxxxxxx" disabled={status === 'loading'} />

                        <button className="btn btn-primary w-100 mt-2" type="submit" disabled={status === 'loading'}>
                            {status === 'loading' ? 'Checking…' : 'Track Now'}
                        </button>

                        <p className="text-center signup-footer">
                            Back to menu? <button type="button" className="text-link-btn" onClick={() => navigate('/cafe')}>Open Cafe</button>
                        </p>
                    </form>

                    {order && (
                        <div className="alert alert-info mt-3">
                            <div><strong>Status:</strong> {String(order.status ?? 'unknown')}</div>
                            <div><strong>Total:</strong> {currency(Number(order.total ?? 0))}</div>
                            <div><strong>Order type:</strong> {String(order.order_type ?? 'pickup')}</div>
                            {timeline.length > 0 && (
                                <ul className="mb-0 mt-2">
                                    {timeline.map((item, index) => (
                                        <li key={index}>{String(item.status ?? '')} — {String(item.note ?? '')}</li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </div>
    )
}

// ─── Cafe Page ────────────────────────────────────────────────────────────────
function CafePage({ navigate }: { navigate: NavigateFn }) {
    const [selectedCategory, setSelectedCategory] = useState('all')
    const [search, setSearch] = useState('')
    const [cart, setCart] = useState<CartItem[]>([])
    const [products, setProducts] = useState<Product[]>(fallbackProducts)
    const [usingFallback, setUsingFallback] = useState(false)
    const [customer, setCustomer] = useState({
        name: '',
        phone: '',
        orderType: 'Pickup',
        paymentMethod: 'Cash',
        notes: '',
    })
    const [checkoutState, setCheckoutState] = useState<'idle' | 'submitting' | 'success' | 'error'>('idle')
    const [checkoutMessage, setCheckoutMessage] = useState('')

    // Load products from backend
    useEffect(() => {
        let alive = true
        async function loadProducts() {
            try {
                const response = await fetch(apiUrl('/products_api.php'), { credentials: 'include' })
                const data = await response.json()
                if (!response.ok || !data.success || !Array.isArray(data.products)) {
                    throw new Error(data.message || 'Unable to load products')
                }
                const mapped = (data.products as ApiProduct[]).map(mapApiProduct)
                if (alive && mapped.length > 0) {
                    setProducts(mapped)
                    setUsingFallback(false)
                } else if (alive) {
                    setUsingFallback(true)
                }
            } catch {
                if (alive) setUsingFallback(true)
            }
        }
        loadProducts()
        return () => { alive = false }
    }, [])

    const visibleProducts = useMemo(() => products.filter(p => {
        const matchesCategory = selectedCategory === 'all' || p.category === selectedCategory
        const matchesSearch = `${p.name} ${p.description} ${p.category}`.toLowerCase().includes(search.toLowerCase())
        return matchesCategory && matchesSearch
    }), [products, search, selectedCategory])

    const categories = useMemo(
        () => ['all', ...Array.from(new Set(products.map(p => p.category).filter(Boolean)))],
        [products]
    )

    const totalItems = cart.reduce((sum, item) => sum + item.quantity, 0)
    const totalPrice = cart.reduce((sum, item) => sum + item.price * item.quantity, 0)

    function addToCart(product: Product) {
        if (product.stock <= 0) return
        setCart(current => {
            const existing = current.find(item => item.id === product.id)
            if (existing) {
                // Don't exceed available stock
                if (existing.quantity >= product.stock) return current
                return current.map(item => item.id === product.id ? { ...item, quantity: item.quantity + 1 } : item)
            }
            return [...current, { ...product, quantity: 1 }]
        })
    }

    function changeQuantity(id: number, delta: number) {
        setCart(current =>
            current
                .map(item => item.id === id ? { ...item, quantity: item.quantity + delta } : item)
                .filter(item => item.quantity > 0)
        )
    }

    async function placeOrder() {
        // Validate customer name
        if (!customer.name.trim()) {
            setCheckoutState('error')
            setCheckoutMessage('Please enter your name before placing an order.')
            return
        }
        if (cart.length === 0) {
            setCheckoutState('error')
            setCheckoutMessage('Your cart is empty. Add items first!')
            return
        }

        setCheckoutState('submitting')
        setCheckoutMessage('Placing your order…')

        try {
            const payMethod = customer.paymentMethod.toLowerCase().includes('gcash') ? 'gcash'
                : customer.paymentMethod.toLowerCase().includes('card') ? 'card'
                : 'cash'

            const response = await fetch(apiUrl('/place_order.php'), {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    customer_name:  customer.name.trim(),
                    customer_phone: customer.phone.trim(),
                    note:           customer.notes.trim(),
                    order_type:     customer.orderType.toLowerCase() === 'dine in' ? 'pickup' : 'pickup',
                    payment_method: payMethod,
                    delivery_lat:   0,
                    delivery_lng:   0,
                    items: cart.map(item => ({ product_id: item.id, quantity: item.quantity })),
                }),
            })

            const data = await response.json()
            if (!response.ok || !data.success) throw new Error(data.message || 'Unable to place order')

            setCart([])
            setCheckoutState('success')
            setCheckoutMessage(`Order placed! Your code: ${data.order_code || 'KC-' + data.order_id}. Estimated time: ${data.estimated_minutes || 20} min.`)
        } catch (error) {
            setCheckoutState('error')
            setCheckoutMessage(error instanceof Error ? error.message : 'Unable to place order. Please try again.')
        }
    }

    return (
        <div className="cafe-page">
            <header className="cafe-header">
                <div className="header-wrap">
                    <div className="brand-block">
                        <span className="brand-name">Klint&apos;s Cafe</span>
                    </div>
                    <div className="header-actions">
                        <button type="button" className="header-link" onClick={() => navigate('/track')}>Track Order</button>
                        <button type="button" className="header-link" onClick={() => navigate('/')}>Staff Login</button>
                    </div>
                </div>
            </header>

            <section className="hero-banner">
                <div className="hero-overlay">
                    <p className="hero-kicker">Welcome to Klint&apos;s Cafe</p>
                    <h1>Order Menu</h1>
                    <p>Browse our freshly prepared coffee, drinks, and food. Order pickup or dine-in below.</p>
                    <div className="hero-actions">
                        <button type="button" className="hero-btn primary"
                            onClick={() => document.querySelector('#menu')?.scrollIntoView({ behavior: 'smooth' })}>
                            Browse menu
                        </button>
                        <button type="button" className="hero-btn"
                            onClick={() => document.querySelector('#cart')?.scrollIntoView({ behavior: 'smooth' })}>
                            Open order panel
                        </button>
                    </div>
                </div>
            </section>

            {usingFallback && (
                <div style={{ background: '#fff3cd', color: '#856404', padding: '8px 16px', textAlign: 'center', fontSize: '0.875rem' }}>
                    ⚠️ Showing demo menu — live database unavailable right now.
                </div>
            )}

            <section className="menu-section" id="menu">
                <div className="menu-content">
                    <div className="menu-header">
                        <h2>Our Menu</h2>
                        <div className="menu-toolbar">
                            <div className="category-filters">
                                {categories.map(cat => (
                                    <button key={cat} type="button"
                                        className={`category-chip ${selectedCategory === cat ? 'active' : ''}`}
                                        onClick={() => setSelectedCategory(cat)}>
                                        {cat.charAt(0).toUpperCase() + cat.slice(1)}
                                    </button>
                                ))}
                            </div>
                            <input type="search" className="menu-search" value={search}
                                onChange={e => setSearch(e.target.value)}
                                placeholder="Search menu items…" />
                        </div>
                    </div>

                    <div className="menu-grid">
                        {visibleProducts.length === 0 ? (
                            <p style={{ gridColumn: '1/-1', textAlign: 'center', color: '#888' }}>No items match your search.</p>
                        ) : visibleProducts.map(product => {
                            const stock = stockLabel(product.stock)
                            const inCart = cart.find(i => i.id === product.id)
                            return (
                                <article className="menu-item" key={product.id}>
                                    <img
                                        src={product.image}
                                        alt={product.name}
                                        onError={e => { (e.currentTarget as HTMLImageElement).src = 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?w=900&h=700&fit=crop' }}
                                    />
                                    {product.featured && <span className="featured-tag">Best Seller</span>}
                                    <h3>{product.name}</h3>
                                    <span className="category-badge">{product.category}</span>
                                    <span className={`stock-badge ${stock.className}`}>{stock.text}</span>
                                    <p>{product.description}</p>
                                    <div className="menu-item-footer">
                                        <span className="menu-price">{currency(product.price)}</span>
                                        {inCart ? (
                                            <div className="cart-controls inline">
                                                <button type="button" onClick={() => changeQuantity(product.id, -1)}>−</button>
                                                <span>{inCart.quantity}</span>
                                                <button type="button" onClick={() => changeQuantity(product.id, 1)} disabled={inCart.quantity >= product.stock}>+</button>
                                            </div>
                                        ) : (
                                            <button className="add-btn" type="button" onClick={() => addToCart(product)} disabled={product.stock <= 0}>
                                                {product.stock <= 0 ? 'Sold out' : 'Add'}
                                            </button>
                                        )}
                                    </div>
                                </article>
                            )
                        })}
                    </div>
                </div>

                <aside className="order-sidebar" id="cart">
                    <h2>Your Order</h2>

                    <form className="customer-form" onSubmit={e => e.preventDefault()}>
                        <label htmlFor="custName">Your name *</label>
                        <input id="custName" value={customer.name}
                            onChange={e => setCustomer({ ...customer, name: e.target.value })}
                            placeholder="Enter your name" required />

                        <label htmlFor="custPhone">Phone (optional)</label>
                        <input id="custPhone" value={customer.phone}
                            onChange={e => setCustomer({ ...customer, phone: e.target.value })}
                            placeholder="09xx xxx xxxx" />

                        <label htmlFor="orderType">Order type</label>
                        <select id="orderType" value={customer.orderType}
                            onChange={e => setCustomer({ ...customer, orderType: e.target.value })}>
                            <option>Pickup</option>
                            <option>Dine In</option>
                        </select>

                        <label htmlFor="paymentMethod">Payment method</label>
                        <select id="paymentMethod" value={customer.paymentMethod}
                            onChange={e => setCustomer({ ...customer, paymentMethod: e.target.value })}>
                            <option>Cash</option>
                            <option>GCash</option>
                            <option>Card</option>
                        </select>

                        <label htmlFor="notes">Special notes</label>
                        <textarea id="notes" rows={3} value={customer.notes}
                            onChange={e => setCustomer({ ...customer, notes: e.target.value })}
                            placeholder="Any special requests or allergies?" />
                    </form>

                    <div className="cart-items">
                        {cart.length === 0 ? (
                            <p className="cart-empty">Cart is empty — add items from the menu!</p>
                        ) : (
                            cart.map(item => (
                                <div key={item.id} className="cart-item">
                                    <div>
                                        <strong>{item.name}</strong>
                                        <p>{currency(item.price)} × {item.quantity} = {currency(item.price * item.quantity)}</p>
                                    </div>
                                    <div className="cart-controls">
                                        <button type="button" onClick={() => changeQuantity(item.id, -1)}>−</button>
                                        <span>{item.quantity}</span>
                                        <button type="button" onClick={() => changeQuantity(item.id, 1)}>+</button>
                                    </div>
                                </div>
                            ))
                        )}
                    </div>

                    <div className="cart-summary">
                        <p>Total items: <strong>{totalItems}</strong></p>
                        <h3>{currency(totalPrice)}</h3>
                        <button type="button" className="place-order-btn" onClick={placeOrder}
                            disabled={checkoutState === 'submitting' || cart.length === 0}>
                            {checkoutState === 'submitting' ? 'Placing order…' : 'Place Order'}
                        </button>
                        {checkoutMessage && (
                            <div className={`alert mt-3 ${checkoutState === 'error' ? 'alert-danger' : 'alert-success'}`}>
                                {checkoutMessage}
                            </div>
                        )}
                    </div>
                </aside>
            </section>
        </div>
    )
}

// ─── App Root ─────────────────────────────────────────────────────────────────
export default function App() {
    // Use hash-based routing so Vercel's SPA catch-all always loads index.html
    const [page, setPage] = useState<PageName>(() => getPageFromHash(window.location.hash))

    useEffect(() => {
        const onHashChange = () => setPage(getPageFromHash(window.location.hash))
        window.addEventListener('hashchange', onHashChange)
        return () => window.removeEventListener('hashchange', onHashChange)
    }, [])

    function navigate(path: string) {
        const hash = path.startsWith('/') ? '#' + path : path
        window.location.hash = hash
        setPage(getPageFromHash(hash))
    }

    if (page === 'signup') return <SignupPage navigate={navigate} />
    if (page === 'track')  return <TrackOrderPage navigate={navigate} />
    if (page === 'cafe')   return <CafePage navigate={navigate} />
    return <LoginPage navigate={navigate} />
}
