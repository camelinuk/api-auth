# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a hotel booking system that demonstrates MCP (Model Context Protocol) integration with a sub-agent architecture. The system uses an agent-based approach to handle customer requests, routing them to specialized agents that interact with MCP tools for room searches, bookings, and cancellations.

## Running the System

```bash
# Run the MCP server (used by Claude Desktop)
node hotel-server.js

# Run the demo (standalone simulation)
node demo.js

# Run integration tests
node test-integration.js
```

## Architecture

### Three-Layer System Design

1. **MCP Server Layer** (`hotel-server.js`)
   - Exposes MCP tools via stdio transport
   - Manages hotel database (rooms, bookings)
   - Tools: `search_rooms`, `create_booking`, `get_booking`, `cancel_booking`
   - Runs as a standalone process, typically connected to Claude Desktop

2. **Agent Orchestration Layer** (`hotel-agents.js`)
   - `CoordinatorAgent`: Routes user requests to appropriate sub-agents based on intent detection
   - `SearchAgent`: Handles room search queries
   - `BookingAgent`: Manages reservation processes
   - `CustomerServiceAgent`: Handles general inquiries
   - All agents inherit from `BaseAgent`

3. **Integration Layer** (`demo.js`)
   - `HotelBookingSystem`: Connects coordinator with MCP client
   - `MCPClient`: Simulates MCP tool calls for testing (in production, actual MCP tools are used)
   - Orchestrates full workflow: user input → agent routing → MCP execution → response generation

### Data Flow

```
User Input
    ↓
CoordinatorAgent (intent detection)
    ↓
Specific Sub-Agent (SearchAgent/BookingAgent/CustomerServiceAgent)
    ↓
MCP Tool Call (via hotel-server.js)
    ↓
Database Operation
    ↓
Response Generation
    ↓
User Output
```

### Intent Detection

The `CoordinatorAgent.route()` method uses simple keyword matching:
- Keywords like "查詢", "搜尋", "有哪些" → `SearchAgent`
- Keywords like "訂房", "預訂", "預約" → `BookingAgent`
- Default → `CustomerServiceAgent`

## Database Schema

In-memory database structure in `hotel-server.js`:

```javascript
hotelDatabase = {
  rooms: [
    { id: number, type: string, price: number, available: number }
  ],
  bookings: [
    {
      id: string,           // Format: "BK{timestamp}"
      room_id: number,
      guest_name: string,
      check_in: string,
      check_out: string,
      status: string,
      created_at: string
    }
  ]
}
```

## MCP Configuration

To use with Claude Desktop, add to `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "hotel-booking": {
      "command": "node",
      "args": ["/path/to/hotel-booking-mcp/hotel-server.js"]
    }
  }
}
```

## File Organization

- `hotel-server.js`: MCP server implementation (shebang for CLI execution)
- `hotel-agents.js`: Agent class definitions (ES modules)
- `demo.js`: Full system demonstration with simulated MCP client
- `test-integration.js`: Integration tests for the coordinator
- `.claude/prompts/hotel-booking.md`: Prompt template for AI assistant behavior

## Important Implementation Notes

- The system uses **ES modules** (`type: "module"` in package.json with `.js` extension for imports)
- MCP server uses **stdio transport** for Claude Desktop communication
- Error logging goes to `console.error` to avoid interfering with stdio protocol
- Booking IDs are generated with `BK${Date.now()}` format
- Room availability is decremented on booking, incremented on cancellation
- All text/messages are in Traditional Chinese (繁體中文)

## Extending the System

When adding new features:

1. **New MCP Tools**: Add to `ListToolsRequestSchema` handler and implement in `CallToolRequestSchema` switch statement in `hotel-server.js`
2. **New Agent Types**: Extend `BaseAgent` class and register in `CoordinatorAgent.agents` dictionary
3. **New Intent Patterns**: Update `CoordinatorAgent.route()` keyword matching logic
4. **New Response Templates**: Modify `HotelBookingSystem.generateResponse()` in `demo.js`
