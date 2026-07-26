import { AuthProvider } from '@/context/AuthContext'
import { ThemeProvider } from '@/context/ThemeContext'
import { ToastProvider } from '@/components/ui/Toast'
import { MoneyVisibilityProvider } from '@/context/MoneyVisibilityContext'

export default function Providers({ children }) {
  return (
    <ThemeProvider>
      <ToastProvider>
        <AuthProvider>
          <MoneyVisibilityProvider>
            {children}
          </MoneyVisibilityProvider>
        </AuthProvider>
      </ToastProvider>
    </ThemeProvider>
  )
}
