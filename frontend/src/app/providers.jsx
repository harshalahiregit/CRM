import { AuthProvider } from '@/context/AuthContext'
import { ThemeProvider } from '@/context/ThemeContext'
import { ToastProvider } from '@/components/ui/Toast'
import { MoneyVisibilityProvider } from '@/context/MoneyVisibilityContext'
import { SireContextProvider } from '@/context/SireContextProvider'

export default function Providers({ children }) {
  return (
    <ThemeProvider>
      <ToastProvider>
        <AuthProvider>
          <MoneyVisibilityProvider>
            {/* SIRE report-issue state. Innermost: it needs the
                authenticated user, and nothing above it needs to know
                it exists. */}
            <SireContextProvider>
              {children}
            </SireContextProvider>
          </MoneyVisibilityProvider>
        </AuthProvider>
      </ToastProvider>
    </ThemeProvider>
  )
}
