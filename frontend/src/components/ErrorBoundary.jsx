import { Component } from 'react'
import { AlertOctagon } from 'lucide-react'

export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = { hasError: false, error: null }
  }

  static getDerivedStateFromError(error) {
    return { hasError: true, error }
  }

  componentDidCatch(error, info) {
    console.error('ErrorBoundary caught an error:', error, info)
  }

  /**
   * Clear the error when `resetKey` changes — the route, usually.
   *
   * A boundary LATCHES: once it has caught, it keeps rendering the fallback
   * until its state is cleared, so without this a single crashed page would
   * follow the user to every screen they tried next, and only a manual reload
   * would get them out.
   *
   * Deliberately not `key={pathname}` at the call site, which would do the same
   * job by remounting. That remounts the entire page subtree on every
   * navigation, including between two routes that share a component, and would
   * quietly change fetch-and-state behaviour across the whole app to fix an
   * error path. This touches nothing unless an error is actually on screen.
   */
  componentDidUpdate(prevProps) {
    if (this.state.hasError && prevProps.resetKey !== this.props.resetKey) {
      this.setState({ hasError: false, error: null })
    }
  }

  handleReset = () => {
    this.setState({ hasError: false, error: null })
    if (this.props.onReset) this.props.onReset()
  }

  render() {
    if (this.state.hasError) {
      if (this.props.fallback) {
        return this.props.fallback(this.state.error, this.handleReset)
      }
      return (
        <div className="flex flex-col items-center justify-center min-h-[50vh] gap-4 text-center px-4">
          <div
            className="w-16 h-16 rounded-3xl flex items-center justify-center"
            style={{ background: 'rgba(239,68,68,0.12)', border: '1px solid rgba(239,68,68,0.25)' }}
          >
            <AlertOctagon size={28} style={{ color: '#f87171' }} />
          </div>
          <div>
            <h2 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Something went wrong</h2>
            <p className="text-sm mt-1 max-w-sm" style={{ color: 'var(--text-muted)' }}>
              {this.state.error?.message || 'An unexpected error occurred while rendering this page.'}
            </p>
          </div>
          <button onClick={this.handleReset} className="btn-3d">Try again</button>
        </div>
      )
    }

    return this.props.children
  }
}
