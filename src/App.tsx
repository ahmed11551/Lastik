import React, { useState } from 'react';
import { AppProvider, useApp } from './context/AppContext';
import { Header } from './components/Header';
import { Sidebar, ViewTab } from './components/Sidebar';

// Views
import { DashboardView } from './views/DashboardView';
import { OrdersView } from './views/OrdersView';
import { NewOrderWizard } from './views/NewOrderWizard';
import { CustomersView } from './views/CustomersView';
import { VehiclesView } from './views/VehiclesView';
import { StockView } from './views/StockView';
import { CashShiftView } from './views/CashShiftView';
import { KPIView } from './views/KPIView';
import { AuditView } from './views/AuditView';
import { UsersRolesView } from './views/UsersRolesView';
import { TenantsLocationsView } from './views/TenantsLocationsView';
import { TasksView } from './views/TasksView';
import { ModulesView } from './views/ModulesView';
import { TVDisplayView } from './views/TVDisplayView';

import { ShieldAlert, LifeBuoy, Activity } from 'lucide-react';

const MainLayout: React.FC = () => {
  const { isSupportAccessActive } = useApp();
  const [currentView, setCurrentView] = useState<ViewTab>('dashboard');
  const [globalSearch, setGlobalSearch] = useState('');

  const handleOpenNewOrder = () => {
    setCurrentView('new_order');
  };

  return (
    <div className="min-h-screen bg-[#020408] text-slate-300 flex flex-col font-sans selection:bg-cyan-500 selection:text-slate-950 relative overflow-hidden">
      {/* Immersive Background Ambient Glows */}
      <div className="fixed top-[-15%] left-[-10%] w-[45%] h-[45%] bg-blue-900/15 rounded-full blur-[140px] pointer-events-none z-0"></div>
      <div className="fixed bottom-[-15%] right-[-10%] w-[45%] h-[45%] bg-indigo-900/15 rounded-full blur-[140px] pointer-events-none z-0"></div>
      <div className="fixed top-[40%] right-[30%] w-[35%] h-[35%] bg-cyan-950/20 rounded-full blur-[160px] pointer-events-none z-0"></div>
      
      {/* Sci-Fi Grid Overlay */}
      <div className="fixed inset-0 bg-tech-grid pointer-events-none opacity-20 z-0"></div>

      {/* Support Access Banner */}
      {isSupportAccessActive && (
        <div className="bg-gradient-to-r from-amber-600 via-amber-500 to-amber-600 text-slate-950 font-bold px-4 py-1.5 text-xs flex items-center justify-between shadow-[0_0_20px_rgba(245,158,11,0.3)] z-50 relative border-b border-amber-400/40">
          <div className="flex items-center gap-2 font-mono tracking-wider">
            <ShieldAlert className="w-4 h-4 animate-pulse" />
            <span>ВКЛЮЧЕН РЕЖИМ ТЕХНИЧЕСКОЙ ПОДДЕРЖКИ ПЛАТФОРМЫ // ВСЕ ДЕЙСТВИЯ ПРОТОКОЛИРУЮТСЯ В НЕИЗМЕНЯЕМЫЙ АУДИТ</span>
          </div>
          <div className="flex items-center gap-1 text-[11px] font-mono bg-slate-950/30 px-2 py-0.5 rounded border border-slate-950/20">
            <LifeBuoy className="w-3.5 h-3.5 text-slate-950" />
            <span>Служба поддержки LASTIK</span>
          </div>
        </div>
      )}

      {/* Main Header */}
      <div className="relative z-10">
        <Header
          onOpenNewOrder={handleOpenNewOrder}
          onSearchChange={setGlobalSearch}
        />
      </div>

      {/* Main Layout Content */}
      <div className="flex-1 flex overflow-hidden relative z-10">
        {/* Navigation Sidebar */}
        <Sidebar
          currentTab={currentView}
          onSelectTab={(tab) => setCurrentView(tab)}
        />

        {/* Dynamic View Area */}
        <main className="flex-1 p-4 md:p-6 overflow-y-auto max-w-7xl mx-auto w-full space-y-6">
          {currentView === 'dashboard' && (
            <DashboardView
              onOpenNewOrder={handleOpenNewOrder}
              onNavigateView={(view) => setCurrentView(view as ViewTab)}
            />
          )}

          {currentView === 'orders' && (
            <OrdersView
              searchTerm={globalSearch}
              onOpenNewOrder={handleOpenNewOrder}
            />
          )}

          {currentView === 'new_order' && (
            <NewOrderWizard
              onComplete={() => setCurrentView('orders')}
            />
          )}

          {currentView === 'customers' && <CustomersView />}

          {currentView === 'vehicles' && <VehiclesView />}

          {currentView === 'stock' && <StockView />}

          {currentView === 'shifts' && <CashShiftView />}

          {currentView === 'kpi' && <KPIView />}

          {currentView === 'audit' && <AuditView />}

          {currentView === 'users' && <UsersRolesView />}

          {currentView === 'tenants' && <TenantsLocationsView />}

          {currentView === 'tasks' && <TasksView />}

          {currentView === 'modules' && <ModulesView />}

          {currentView === 'tv_display' && <TVDisplayView />}
        </main>
      </div>

      {/* Subtly Styled Footer Telemetry Bar */}
      <footer className="h-9 bg-black/60 border-t border-white/5 px-4 flex items-center justify-between font-mono text-[10px] text-slate-500 relative z-10">
        <div className="flex items-center gap-4">
          <span className="flex items-center gap-1 text-slate-400">
            <Activity className="w-3 h-3 text-cyan-400 animate-pulse" />
            LASTIK OPERATIONAL CORE v1.0
          </span>
          <span className="hidden sm:inline text-slate-600">//</span>
          <span className="hidden sm:inline text-slate-400">LATENCY: 12ms</span>
          <span className="hidden sm:inline text-slate-600">//</span>
          <span className="hidden sm:inline text-emerald-400">ENCRYPTION: AES-256</span>
        </div>
        <div className="flex items-center gap-3">
          <span className="text-cyan-400/80">ORBITAL-4 PROTOCOL</span>
          <span className="w-1.5 h-1.5 rounded-full bg-cyan-400 animate-ping"></span>
        </div>
      </footer>
    </div>
  );
};

export default function App() {
  return (
    <AppProvider>
      <MainLayout />
    </AppProvider>
  );
}
