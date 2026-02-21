<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopify Customer Discount Dashboard</title>
    <script src="https://unpkg.com/@shopify/app-bridge@3"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/react@18/umd/react.development.js"></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.development.js"></script>
    <script src="https://unpkg.com/babel-standalone@6/babel.min.js"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "San Francisco", "Segoe UI", Roboto, "Helvetica Neue", sans-serif; background-color: #f6f6f7; }
        .polaris-card { background: white; border-radius: 8px; box-shadow: 0 0 0 1px rgba(63, 63, 68, 0.05), 0 1px 3px 0 rgba(63, 63, 68, 0.15); padding: 20px; }
        
        @keyframes bounce-short {
            0%, 20%, 50%, 80%, 100% {transform: translateY(0);}
            40% {transform: translateY(-5px);}
            60% {transform: translateY(-3px);}
        }
        .animate-bounce-short {
            animation: bounce-short 0.5s ease;
        }
        
        @keyframes fade-in-right {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        .animate-fade-in {
            animation: fade-in-right 0.3s ease-out;
        }
    </style>
</head>
<body class="p-8">
    <div id="app"></div>

    <script type="text/babel">
        const { useState, useEffect } = React;

        const App = () => {
            const [activeTab, setActiveTab] = useState('customers');
            const [customers, setCustomers] = useState([]);
            const [products, setProducts] = useState([]);
            const [collections, setCollections] = useState([]);
            const [loading, setLoading] = useState(true);
            const [toasts, setToasts] = useState([]);
            
            // Modals & State
            const [isCreatingCustomer, setIsCreatingCustomer] = useState(false);
            const [newCustomer, setNewCustomer] = useState({ first_name: '', last_name: '', email: '' });
            
            const [isEditingEntity, setIsEditingEntity] = useState(null); // 'customer', 'product', 'collection'
            const [editingData, setEditingData] = useState(null);
            
            const [isManagingDiscount, setIsManagingDiscount] = useState(null); // customer object
            const [isViewingDetails, setIsViewingDetails] = useState(null); // detail object
            const [selectedTargets, setSelectedTargets] = useState([]); // Array of IDs
            const [targetType, setTargetType] = useState('all');
            
            const shop = new URLSearchParams(location.search).get("shop");

            useEffect(() => {
                fetchAllData();
            }, []);

            const showToast = (message, type = 'success') => {
                const id = Date.now();
                setToasts(prev => [...prev, { id, message, type }]);
                setTimeout(() => {
                    setToasts(prev => prev.filter(t => t.id !== id));
                }, 5000);
            };

            const fetchAllData = async () => {
                setLoading(true);
                try {
                    const [custRes, prodRes, collRes] = await Promise.all([
                        fetch('/api/customers'),
                        fetch('/api/products'),
                        fetch('/api/collections')
                    ]);

                    if (!custRes.ok || !prodRes.ok || !collRes.ok) {
                        throw new Error("One or more server requests failed (possibly a controller error).");
                    }

                    const [custData, prodData, collData] = await Promise.all([
                        custRes.json(),
                        prodRes.json(),
                        collRes.json()
                    ]);

                    setCustomers(custData);
                    setProducts(prodData);
                    setCollections(collData);
                } catch (error) {
                    console.error(error);
                    showToast("Failed to fetch data: " + error.message, 'error');
                } finally {
                    setLoading(false);
                }
            };

            const handleSync = async (type) => {
                setLoading(true);
                try {
                    const response = await fetch(`/api/${type}/sync?shop=${shop}`, { method: 'POST' });
                    const data = await response.json();
                    if (!response.ok) throw new Error(data.error || 'Sync failed');
                    showToast(`${type.charAt(0).toUpperCase() + type.slice(1)} sync completed!`);
                    fetchAllData();
                } catch (error) {
                    showToast(error.message, 'error');
                    setLoading(false);
                }
            };

            const handleRequest = async (url, method, body = null) => {
                setLoading(true);
                try {
                    const options = {
                        method,
                        headers: { 'Content-Type': 'application/json' },
                    };
                    if (body) options.body = JSON.stringify({ ...body, shop });
                    
                    const res = await fetch(url, options);
                    
                    // Handle non-OK responses
                    if (!res.ok) {
                        let errorMessage = 'Operation failed';
                        try {
                            const data = await res.json();
                            errorMessage = data.error || errorMessage;
                        } catch (e) {
                            // If not JSON, use status text
                            errorMessage = res.statusText || errorMessage;
                        }
                        throw new Error(errorMessage);
                    }

                    // For 204 No Content or empty responses, return a success flag instead of parsing JSON
                    if (res.status === 204 || res.headers.get('content-length') === '0') {
                        return { success: true };
                    }

                    return await res.json();
                } catch (error) {
                    showToast(error.message, 'error');
                    return null;
                } finally {
                    setLoading(false);
                }
            };

            const handleCreateCustomer = async (e) => {
                e.preventDefault();
                const res = await handleRequest('/api/customers', 'POST', newCustomer);
                if (res) {
                    showToast("Customer created successfully!");
                    setNewCustomer({ first_name: '', last_name: '', email: '' });
                    setIsCreatingCustomer(false);
                    fetchAllData();
                }
            };

            const handleUpdateEntity = async (e) => {
                e.preventDefault();
                const endpoint = `/api/${isEditingEntity}s/${editingData.id}`;
                const res = await handleRequest(endpoint, 'PUT', editingData);
                if (res) {
                    showToast("Changes saved successfully!");
                    setIsEditingEntity(null);
                    setEditingData(null);
                    fetchAllData();
                }
            };

            const handleDeleteEntity = async (type, id) => {
                if(confirm(`Are you sure you want to delete this ${type}? This will also delete it from Shopify.`)) {
                    const res = await handleRequest(`/api/${type}s/${id}?shop=${shop}`, 'DELETE');
                    if (res !== null) showToast(`${type.charAt(0).toUpperCase() + type.slice(1)} deleted.`);
                    fetchAllData();
                }
            };

            const openDiscountManager = (customer) => {
                setIsManagingDiscount(customer);
                setTargetType(customer.discount_target_type || 'all');
                setSelectedTargets(customer.discount_target_ids || []);
            };

            const saveDiscountSettings = async () => {
                const res = await handleRequest(`/api/customers/${isManagingDiscount.id}/discount`, 'PUT', {
                    discount_percentage: isManagingDiscount.discount_percentage,
                    discount_target_type: targetType,
                    discount_target_ids: selectedTargets
                });
                if (res) {
                    showToast("Discount settings synced to Shopify!");
                    setIsManagingDiscount(null);
                    fetchAllData();
                }
            };

            const handleSendCredentials = async (customerId) => {
                const res = await handleRequest(`/api/customers/${customerId}/send-credentials`, 'POST');
                if (res) {
                    showToast("Credentials sent successfully!");
                }
            };

            return (
                <div className="max-w-6xl mx-auto pb-20 relative">
                    {/* Toast Notification Container */}
                    <div className="fixed top-8 right-8 z-[100] space-y-3 w-80">
                        {toasts.map(toast => (
                            <div key={toast.id} className={`p-4 rounded-xl shadow-2xl flex items-center justify-between transform transition-all animate-fade-in animate-bounce-short ${toast.type === 'error' ? 'bg-red-600 text-white' : 'bg-green-600 text-white'}`}>
                                <div className="flex items-center">
                                    <span className="mr-3 text-lg">{toast.type === 'error' ? '❌' : '✅'}</span>
                                    <p className="text-sm font-bold">{toast.message}</p>
                                </div>
                                <button onClick={() => setToasts(toasts.filter(t => t.id !== toast.id))} className="ml-4 opacity-75 hover:opacity-100 font-bold">×</button>
                            </div>
                        ))}
                    </div>

                    <header className="flex justify-between items-center mb-8">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-800">Shopify Integration Hub</h1>
                            <p className="text-gray-500 text-sm">Automated Customer Discounts & Inventory Sync</p>
                        </div>
                        <div className="flex space-x-3">
                            {activeTab === 'customers' && (
                                <button onClick={() => setIsCreatingCustomer(true)} className="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 transition shadow-sm font-medium">Add Customer</button>
                            )}
                            <button onClick={() => handleSync(activeTab)} className="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-50 transition shadow-sm font-medium flex items-center">
                                <span className="mr-2">🔄</span> Sync {activeTab.charAt(0).toUpperCase() + activeTab.slice(1)}
                            </button>
                        </div>
                    </header>

                    {/* Tabs */}
                    <div className="flex border-b border-gray-200 mb-6">
                        {['customers', 'products', 'collections'].map(tab => (
                            <button key={tab} onClick={() => setActiveTab(tab)} className={`px-6 py-3 text-sm font-medium capitalize transition-colors border-b-2 ${activeTab === tab ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'}`}>
                                {tab}
                            </button>
                        ))}
                    </div>

                    {/* Customer Creation Modal */}
                    {isCreatingCustomer && (
                        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4 backdrop-blur-sm">
                            <div className="bg-white rounded-xl shadow-2xl w-full max-w-md p-6">
                                <h3 className="text-xl font-bold mb-6 text-gray-800">Add New Customer</h3>
                                <form onSubmit={handleCreateCustomer} className="space-y-4">
                                    <div className="grid grid-cols-2 gap-4">
                                        <div>
                                            <label className="block text-xs font-semibold text-gray-500 uppercase mb-1">First Name</label>
                                            <input required value={newCustomer.first_name} onChange={e => setNewCustomer({...newCustomer, first_name: e.target.value})} className="w-full border rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-indigo-500 outline-none" />
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-gray-500 uppercase mb-1">Last Name</label>
                                            <input required value={newCustomer.last_name} onChange={e => setNewCustomer({...newCustomer, last_name: e.target.value})} className="w-full border rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-indigo-500 outline-none" />
                                        </div>
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-500 uppercase mb-1">Email Address</label>
                                        <input required type="email" value={newCustomer.email} onChange={e => setNewCustomer({...newCustomer, email: e.target.value})} className="w-full border rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-indigo-500 outline-none" />
                                    </div>
                                    <div className="flex justify-end space-x-3 pt-6 border-t mt-6">
                                        <button type="button" onClick={() => setIsCreatingCustomer(false)} disabled={loading} className="text-gray-500 hover:text-gray-700 px-4 py-2 text-sm font-medium disabled:opacity-50">Cancel</button>
                                        <button type="submit" disabled={loading} className="bg-indigo-600 text-white px-6 py-2 rounded-lg hover:bg-indigo-700 text-sm font-semibold transition disabled:opacity-50">
                                            {loading ? 'Processing...' : 'Save to Shopify'}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    )}

                    {/* Edit Entity Modal */}
                    {isEditingEntity && (
                        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4 backdrop-blur-sm">
                            <div className="bg-white rounded-xl shadow-2xl w-full max-w-md p-6">
                                <h3 className="text-xl font-bold mb-6 text-gray-800 capitalize">Edit {isEditingEntity}</h3>
                                <form onSubmit={handleUpdateEntity} className="space-y-4">
                                    {isEditingEntity === 'customer' ? (
                                        <React.Fragment>
                                            <div className="grid grid-cols-2 gap-4">
                                                <div>
                                                    <label className="block text-xs font-semibold text-gray-500 uppercase mb-1">First Name</label>
                                                    <input required value={editingData.first_name} onChange={e => setEditingData({...editingData, first_name: e.target.value})} className="w-full border rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-indigo-500 outline-none" />
                                                </div>
                                                <div>
                                                    <label className="block text-xs font-semibold text-gray-500 uppercase mb-1">Last Name</label>
                                                    <input required value={editingData.last_name} onChange={e => setEditingData({...editingData, last_name: e.target.value})} className="w-full border rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-indigo-500 outline-none" />
                                                </div>
                                            </div>
                                            <div>
                                                <label className="block text-xs font-semibold text-gray-500 uppercase mb-1">Email Address</label>
                                                <input required type="email" value={editingData.email} onChange={e => setEditingData({...editingData, email: e.target.value})} className="w-full border rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-indigo-500 outline-none" />
                                            </div>
                                        </React.Fragment>
                                    ) : (
                                        <div>
                                            <label className="block text-xs font-semibold text-gray-500 uppercase mb-1">Title</label>
                                            <input required value={editingData.title} onChange={e => setEditingData({...editingData, title: e.target.value})} className="w-full border rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-indigo-500 outline-none" />
                                        </div>
                                    )}
                                    <div className="flex justify-end space-x-3 pt-6 border-t mt-6">
                                        <button type="button" onClick={() => { setIsEditingEntity(null); setEditingData(null); }} className="text-gray-500 hover:text-gray-700 px-4 py-2 text-sm font-medium">Cancel</button>
                                        <button type="submit" className="bg-indigo-600 text-white px-6 py-2 rounded-lg hover:bg-indigo-700 text-sm font-semibold transition">Update</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    )}

                    {/* Discount Management Modal */}
                    {isManagingDiscount && (
                        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4 backdrop-blur-sm">
                            <div className="bg-white rounded-xl shadow-2xl w-full max-w-2xl p-8 max-h-[90vh] overflow-y-auto">
                                <div className="flex justify-between items-center mb-6">
                                    <h3 className="text-2xl font-bold text-gray-800">Advanced Discount: {isManagingDiscount.first_name}</h3>
                                    <span className="bg-indigo-100 text-indigo-700 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider">Amount Off Products</span>
                                </div>
                                
                                <div className="space-y-8">
                                    {/* Percentage */}
                                    <div className="bg-gray-50 p-4 rounded-lg flex items-center justify-between">
                                        <div>
                                            <h4 className="font-semibold text-gray-700">Discount Value</h4>
                                            <p className="text-sm text-gray-500">Percentage to deduct from eligible items</p>
                                        </div>
                                        <div className="flex items-center space-x-2">
                                            <input type="number" min="0" max="100" value={isManagingDiscount.discount_percentage} onChange={e => setIsManagingDiscount({...isManagingDiscount, discount_percentage: e.target.value})} className="w-24 border rounded-lg p-2 font-bold text-lg text-center focus:ring-2 focus:ring-indigo-500 outline-none" />
                                            <span className="text-xl font-bold text-gray-400">%</span>
                                        </div>
                                    </div>

                                    {/* Application Type */}
                                    <div className="space-y-4">
                                        <h4 className="font-semibold text-gray-700">Applies To</h4>
                                        <div className="grid grid-cols-3 gap-3">
                                            {['all', 'products', 'collections'].map(type => (
                                                <button key={type} onClick={() => setTargetType(type)} className={`p-4 border rounded-xl text-center transition-all ${targetType === type ? 'border-indigo-600 bg-indigo-50 text-indigo-700 shadow-md ring-1 ring-indigo-600' : 'border-gray-200 bg-white text-gray-500 hover:border-indigo-300'}`}>
                                                    <div className="text-lg mb-1">{type === 'all' ? '🏠' : type === 'products' ? '📦' : '🏷️'}</div>
                                                    <div className="text-sm font-bold capitalize">{type}</div>
                                                </button>
                                            ))}
                                        </div>
                                    </div>

                                    {/* Selection List */}
                                    {targetType !== 'all' && (
                                        <div className="space-y-3">
                                            <label className="block text-xs font-bold text-gray-400 uppercase">Select {targetType}</label>
                                            <div className="border rounded-xl divide-y max-h-60 overflow-y-auto bg-white shadow-inner">
                                                {(targetType === 'products' ? products : collections).map(item => (
                                                    <div key={item.shopify_id} className="flex items-center p-3 hover:bg-gray-50 cursor-pointer transition" onClick={() => {
                                                        const id = String(item.shopify_id);
                                                        if (selectedTargets.includes(id)) {
                                                            setSelectedTargets(selectedTargets.filter(t => t !== id));
                                                        } else {
                                                            setSelectedTargets([...selectedTargets, id]);
                                                        }
                                                    }}>
                                                        <input type="checkbox" checked={selectedTargets.includes(String(item.shopify_id))} readOnly className="mr-4 h-4 w-4 text-indigo-600 rounded border-gray-300 pointer-events-none" />
                                                        <span className="text-sm font-medium text-gray-700">{item.title}</span>
                                                    </div>
                                                ))}
                                                {(targetType === 'products' ? products : collections).length === 0 && (
                                                    <div className="p-10 text-center text-gray-400 italic">No {targetType} synced. Please sync inventory first.</div>
                                                )}
                                            </div>
                                            <p className="text-xs text-indigo-500 font-medium">Selected {selectedTargets.length} {targetType}</p>
                                        </div>
                                    )}

                                    <div className="flex justify-end space-x-3 pt-6 border-t font-semibold">
                                        <button onClick={() => setIsManagingDiscount(null)} className="text-gray-500 hover:text-gray-700 px-6 py-2">Discard</button>
                                        <button onClick={saveDiscountSettings} className="bg-indigo-600 text-white px-8 py-2 rounded-xl hover:bg-indigo-700 shadow-lg shadow-indigo-200 transition active:scale-95">Sync to Shopify</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    <div className="polaris-card">
                        {loading && <div className="py-20 text-center flex flex-col items-center">
                            <div className="animate-spin rounded-full h-10 w-10 border-b-2 border-indigo-600 mb-4"></div>
                            <span className="text-gray-400 font-medium tracking-wide">Synchronizing data...</span>
                        </div>}
                        
                        {!loading && activeTab === 'customers' && (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left">
                                    <thead>
                                        <tr className="text-gray-400 text-[10px] font-bold uppercase tracking-[0.1em] border-b">
                                            <th className="pb-4 px-4 w-1/4">Customer</th>
                                            <th className="pb-4 px-4 w-1/4">Email</th>
                                            <th className="pb-4 px-4">Active Discount</th>
                                            <th className="pb-4 px-4 text-right">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {customers.length === 0 ? (
                                            <tr><td colSpan="4" className="py-20 text-center text-gray-400 font-medium italic">No customers synchronized from Shopify.</td></tr>
                                        ) : customers.map(customer => (
                                            <tr key={customer.id} className="group hover:bg-indigo-50/30 transition-colors">
                                                <td className="py-5 px-4">
                                                    <div className="flex items-center">
                                                        <div className="w-8 h-8 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center font-bold text-xs mr-3">
                                                            {customer.first_name ? customer.first_name[0] : (customer.email ? customer.email[0].toUpperCase() : '?')}
                                                            {customer.last_name ? customer.last_name[0] : ''}
                                                        </div>
                                                        <span className="font-semibold text-gray-800">
                                                            {customer.first_name || ''} {customer.last_name || (customer.first_name ? '' : 'Unnamed Customer')}
                                                        </span>
                                                    </div>
                                                </td>
                                                <td className="py-5 px-4 text-gray-500 text-sm font-medium">{customer.email}</td>
                                                <td className="py-5 px-4 text-xs font-semibold">
                                                    {customer.discount_percentage > 0 ? (
                                                        <div className="flex flex-col space-y-1">
                                                            <button onClick={() => openDiscountManager(customer)} className="flex items-center bg-green-50 text-green-700 px-3 py-1 rounded-full border border-green-200 hover:bg-green-100 transition w-fit">
                                                                <span className="mr-1.5">⚡</span> {customer.discount_percentage}% OFF 
                                                            </button>
                                                            <div className="flex items-center space-x-2 text-[10px] text-gray-400 uppercase tracking-tighter">
                                                                <span className="bg-indigo-50 text-indigo-600 px-1.5 py-0.5 rounded font-bold">AUTOMATIC</span>
                                                                <span className="bg-gray-100 px-1.5 py-0.5 rounded">{customer.discount_target_type === 'all' ? 'Global' : ((customer.discount_target_ids || []).length + ' items')}</span>
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <button onClick={() => openDiscountManager(customer)} className="text-gray-400 hover:text-indigo-600 font-bold uppercase tracking-wider transition">Add Discount</button>
                                                    )}
                                                </td>
                                                <td className="py-5 px-4 text-right space-x-4">
                                                    <button onClick={() => handleSendCredentials(customer.id)} className="text-indigo-500 hover:text-indigo-700 text-xs font-bold uppercase">🔑 Invite</button>
                                                    <button onClick={async () => {
                                                        try {
                                                            const res = await fetch(`/api/customers/${customer.id}/details`);
                                                            if (res.status === 404) {
                                                                showToast("This customer has not submitted their details yet.", "error");
                                                                return;
                                                            }
                                                            if (!res.ok) throw new Error("Server Error " + res.status);
                                                            const data = await res.json();
                                                            if (data && data.id) {
                                                                setIsViewingDetails(data);
                                                            } else {
                                                                showToast("No additional details found for this customer.", "error");
                                                            }
                                                        } catch (err) {
                                                            showToast("Failed to fetch details: " + err.message, "error");
                                                        }
                                                    }} className="text-blue-500 hover:text-blue-700 text-xs font-bold uppercase">📋 Info</button>
                                                    <button onClick={() => { setIsEditingEntity('customer'); setEditingData(customer); }} className="text-gray-400 hover:text-indigo-600 text-xs font-bold uppercase">Edit</button>
                                                    <button onClick={() => handleDeleteEntity('customer', customer.id)} className="text-gray-300 hover:text-red-500 text-xs font-bold uppercase transition">Delete</button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {/* Viewing Details Modal */}
                        {isViewingDetails && (
                            <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4 backdrop-blur-sm">
                                <div className="bg-white rounded-xl shadow-2xl w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto">
                                    <div className="flex justify-between items-center mb-6">
                                        <h3 className="text-xl font-bold text-gray-800">Additional Information</h3>
                                        <button onClick={() => setIsViewingDetails(null)} className="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
                                    </div>
                                    <div className="grid grid-cols-2 gap-y-4 gap-x-6 text-sm">
                                        {[
                                            { label: 'Company Name', key: 'company_name' },
                                            { label: 'Physician Name', key: 'physician_name' },
                                            { label: 'NPI #', key: 'npi' },
                                            { label: 'Contact Name', key: 'contact_name' },
                                            { label: 'Contact Email', key: 'contact_email' },
                                            { label: 'Contact Phone', key: 'contact_phone_number' },
                                            { label: 'Sales Rep', key: 'sales_rep' },
                                            { label: 'PO #', key: 'po' },
                                            { label: 'Department', key: 'department' },
                                        ].map(field => (
                                            <div key={field.key} className="border-b pb-2">
                                                <label className="block text-xs font-bold text-gray-400 uppercase mb-1">{field.label}</label>
                                                <p className="text-gray-800 font-medium">{isViewingDetails[field.key] || 'N/A'}</p>
                                            </div>
                                        ))}
                                        <div className="col-span-2 border-b pb-2">
                                            <label className="block text-xs font-bold text-gray-400 uppercase mb-1">Message</label>
                                            <p className="text-gray-800 font-medium whitespace-pre-wrap">{isViewingDetails['message'] || 'No message provided'}</p>
                                        </div>
                                    </div>
                                    <div className="flex justify-end mt-8">
                                        <button onClick={() => setIsViewingDetails(null)} className="bg-gray-100 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-200 transition font-semibold">Close</button>
                                    </div>
                                </div>
                            </div>
                        )}

                        {!loading && activeTab === 'products' && (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left">
                                    <thead>
                                        <tr className="text-gray-400 text-[10px] font-bold uppercase tracking-[0.1em] border-b">
                                            <th className="pb-4 px-4">Media</th>
                                            <th className="pb-4 px-4">Title</th>
                                            <th className="pb-4 px-4">Handle</th>
                                            <th className="pb-4 px-4 text-right">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {products.length === 0 ? (
                                            <tr><td colSpan="4" className="py-20 text-center text-gray-400 italic">No products found.</td></tr>
                                        ) : products.map(product => (
                                            <tr key={product.id} className="hover:bg-gray-50 transition">
                                                <td className="py-4 px-4">
                                                    {product.image_url ? <img src={product.image_url} className="w-12 h-12 rounded-lg border object-cover shadow-sm" /> : <div className="w-12 h-12 bg-gray-100 rounded-lg border flex items-center justify-center text-[8px] text-gray-400 font-bold">MISSING</div>}
                                                </td>
                                                <td className="py-4 px-4 font-bold text-gray-800">{product.title}</td>
                                                <td className="py-4 px-4 text-gray-500 text-sm font-medium">{product.handle}</td>
                                                <td className="py-4 px-4 text-right space-x-3">
                                                    <button onClick={() => { setIsEditingEntity('product'); setEditingData(product); }} className="text-indigo-600 hover:text-indigo-800 text-xs font-bold uppercase">Edit Name</button>
                                                    <button onClick={() => handleDeleteEntity('product', product.id)} className="text-red-400 hover:text-red-600 text-xs font-bold uppercase">Delete</button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {!loading && activeTab === 'collections' && (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left">
                                    <thead>
                                        <tr className="text-gray-400 text-[10px] font-bold uppercase tracking-[0.1em] border-b">
                                            <th className="pb-4 px-4">Collection Title</th>
                                            <th className="pb-4 px-4">Slug Handle</th>
                                            <th className="pb-4 px-4 text-right">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {collections.length === 0 ? (
                                            <tr><td colSpan="3" className="py-20 text-center text-gray-400 italic">No collections found.</td></tr>
                                        ) : collections.map(collection => (
                                            <tr key={collection.id} className="hover:bg-gray-50 transition">
                                                <td className="py-5 px-4 font-bold text-gray-800">{collection.title}</td>
                                                <td className="py-5 px-4 text-gray-500 text-sm font-medium">{collection.handle}</td>
                                                <td className="py-5 px-4 text-right space-x-3">
                                                    <button onClick={() => { setIsEditingEntity('collection'); setEditingData(collection); }} className="text-indigo-600 hover:text-indigo-800 text-xs font-bold uppercase">Edit</button>
                                                    <button onClick={() => handleDeleteEntity('collection', collection.id)} className="text-red-400 hover:text-red-600 text-xs font-bold uppercase">Delete</button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </div>
            );
        };

        const root = ReactDOM.createRoot(document.getElementById('app'));
        root.render(<App />);

        const AppBridge = window['app-bridge'];
        if (AppBridge) {
            const createApp = AppBridge.default || AppBridge;
            const app = createApp({
                apiKey: "{{ env('SHOPIFY_API_KEY') }}",
                host: new URLSearchParams(location.search).get("host"),
                forceRedirect: true
            });
        }
    </script>
</body>
</html>
